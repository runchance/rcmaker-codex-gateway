<?php
declare(strict_types=1);
namespace app\gateway\model;

use app\gateway\database\Schema;

/** Durable ownership and stop-before-close receipts for retired task pages; never performs browser I/O. */
final class TaskPageRetirement
{
    private const SURFACE_FIELDS = 'id,task_id,epoch_id,target_id,status,generation,document_nonce,owner_incarnation,fencing_version,url';
    private const SOURCE_FIELDS = 'id,task_id,surface_id,prompt,submit_intent_at,accepted_at,submitted_document_nonce,baseline_users_json,transport_stage_sent,transport_transaction_id,created_at';

    /**
     * Preserve the old physical page before its source token or saved-chat identity is discarded.
     * The retirement caller owns the writer transaction. An explicitly supplied source is the
     * pre-rotation value and is never replaced with the mutated row for that response ID.
     * @param array $surface Exact surface about to retire, or a still-targeted legacy retired row.
     * @param array|null $source Original source metadata captured before capability rotation.
     * @param array|null $chat Original task chat/account pin captured before conversation release.
     * @return void Existing records and page transfers retain their prior immutable identity.
     * @throws GatewayError On unreadable or unpersistable retirement evidence.
     */
    public static function captureUnlocked(array $surface, ?array $source = null, ?array $chat = null): void
    {
        $id = (string)($surface['id'] ?? '');
        $taskId = (string)($surface['task_id'] ?? '');
        if ($id === '' || $taskId === '') return;
        $db = SDB(null, 'medoo', 'sqlite');
        $existing = $db->table('gateway_surface_retirements')->where('surface_id', $id)->find('surface_id');
        if (is_array($existing)) return;
        if ($existing === false) throw new GatewayError('storage_unavailable', 'Retired page evidence could not be read.', 503);
        $chat ??= TaskWebChat::state($taskId);
        $url = (string)($surface['url'] ?? '');
        $saved = self::savedUrl($url);
        $chatMode = $saved !== null ? 'regular' : (string)($chat['mode'] ?? 'temporary');
        $targetId = (string)($surface['target_id'] ?? '');
        if ($targetId === '' && ($saved === null || $chatMode !== 'regular')) return;
        if (self::transferredUnlocked($surface, $saved)) return;
        $sourceSurfaceId = (string)($surface['origin_surface_id'] ?? $id);
        if (is_array($source) && (($source['task_id'] ?? '') !== $taskId || ($source['surface_id'] ?? '') !== $sourceSurfaceId)) {
            throw new GatewayError('surface_fence_lost', 'Retired page submission belongs to another surface.', 409);
        }
        if (is_array($source) && !array_key_exists('baseline_users_json',$source) && isset($source['baseline_users'])) {
            $source['baseline_users_json'] = self::encode((array)$source['baseline_users']);
        }

        // Projection avoids loading a task's archived context. Find a physical submission even
        // when the latest logical tool round has no own submit/accept fields.
        $physical = $source;
        if (!self::wasSubmitted($physical)) {
            foreach (['submit_intent_at','accepted_at','transport_stage_sent'] as $field) {
                $query = $db->table('gateway_response_jobs')->where('task_id', $taskId)->where('surface_id', $sourceSurfaceId);
                $candidate = $query->where($field, '>', 0)
                    ->order([['rowid','DESC']])->limit(1)->find(self::SOURCE_FIELDS);
                if ($candidate === false) throw new GatewayError('storage_unavailable', 'Retired page submission could not be read.', 503);
                if (is_array($candidate) && self::wasSubmitted($candidate)
                    && ($source === null || $candidate['id'] !== $source['id'])) {
                    $physical = $candidate;
                    break;
                }
            }
        }
        if (!is_array($physical)) {
            $physical = $db->table('gateway_response_jobs')->where('task_id', $taskId)->where('surface_id', $sourceSurfaceId)
                ->order([['rowid','DESC']])->limit(1)->find(self::SOURCE_FIELDS);
            if ($physical === false) throw new GatewayError('storage_unavailable', 'Retired page source could not be read.', 503);
        }
        $submitted = self::wasSubmitted($physical);
        $empty = !$submitted && is_array($physical) && $saved === null
            && ($url === 'about:blank' || (preg_match('~\Ahttps://chatgpt\.com/?(?:\?[^#]*)?(?:#.*)?\z~i', $url) === 1
                && json_decode((string)($physical['baseline_users_json'] ?? ''), true) === []));
        // An old saved URL is positive evidence of a nonempty page even if old versions
        // lost its submission metadata. Preserve the stop obligation, never infer idle.
        // A real target with incomplete legacy metadata is unknown, never an empty page.
        // Keep its stop obligation; absent witnesses must not release a replacement.
        $prompt = (string)($physical['prompt'] ?? '');
        preg_match_all('/\b(?:turn|request)_[A-Za-z0-9_-]{16,}\b/', $prompt, $tokens);
        preg_match_all('/\bctx_[a-f0-9]{16,}\b/i', $prompt, $transactions);
        if (!empty($physical['transport_transaction_id'])) $transactions[0][] = (string)$physical['transport_transaction_id'];
        $hashes = static fn(array $values): array => array_values(array_unique(array_map(
            static fn(string $value): string => hash('sha256', $value), $values)));
        $users = json_decode((string)($physical['baseline_users_json'] ?? ''), true);
        $validUsers = is_array($users) && count(array_filter($users, 'is_string')) === count($users);
        $users = $validUsers ? array_values($users) : [];
        $marker = '';
        if (preg_match('~<rcmaker_chat_binding>(resp_[A-Za-z0-9_-]+)</rcmaker_chat_binding>~', $prompt, $match)) $marker = $match[0];
        $snapshot = [
            'version'=>1, 'surface_id'=>$id, 'task_id'=>$taskId, 'epoch_id'=>(string)$surface['epoch_id'],
            'target_id'=>$targetId, 'url'=>$saved ?? $url, 'generation'=>(int)$surface['generation'],
            'owner_incarnation'=>(string)$surface['owner_incarnation'], 'fencing_version'=>(int)$surface['fencing_version'],
            'document_nonce'=>(string)$surface['document_nonce'], 'chat_mode'=>$chatMode,
            'account_key'=>$chat['account_key'] ?? null, 'source_response_id'=>$physical['id'] ?? null,
            'baseline_users'=>$users, 'accepted_initial'=>!empty($physical['submit_intent_at']) && !empty($physical['accepted_at'])
                && ($physical['submitted_document_nonce'] ?? null) === $surface['document_nonce'] && $validUsers && $users === [],
            'initial_binding_marker'=>$marker, 'turn_token_hashes'=>$hashes($tokens[0]),
            'transaction_hashes'=>$hashes($transactions[0]), 'empty_page'=>$empty,
        ];
        if ($sourceSurfaceId !== $id) $snapshot['origin_surface_id'] = $sourceSurfaceId;
        if (!$db->table('gateway_surface_retirements')->add([
            'surface_id'=>$id, 'task_id'=>$taskId, 'state'=>$empty ? 'stopped' : 'pending',
            'snapshot_json'=>self::encode($snapshot), 'created_at'=>time(), 'updated_at'=>time(),
        ])) throw new GatewayError('storage_unavailable', 'Retired page stop obligation could not be saved.', 503);
    }

    /**
     * Preserve a discarded staging page while its queued logical surface stays in the same epoch.
     * The caller owns the repair transaction and has proved no executable commit or host effects.
     * A dedicated retired epoch satisfies the one-surface-per-epoch constraint without changing
     * compaction provenance, the current turn, its capability or the task's active epoch.
     * @param array $surface Immutable pre-repair physical identity.
     * @param string $responseId Original unsent source, read with metadata/control-prompt projection only.
     * @param array $chat Pre-repair task chat/account state; saved-chat reopen does not call this method.
     * @return string|null Retired physical surface ID, or null when no physical page ever existed.
     * @throws GatewayError On stale ownership, missing source evidence or failed transactional writes.
     */
    public static function preserveReplacedSurfaceUnlocked(array $surface, string $responseId, array $chat): ?string
    {
        if (empty($surface['target_id']) && self::savedUrl((string)($surface['url'] ?? '')) === null) return null;
        $db = SDB(null, 'medoo', 'sqlite');
        $current = $db->table('gateway_browser_surfaces')->where('id', $surface['id'])->find();
        foreach (['task_id','epoch_id','target_id','document_nonce','owner_incarnation','fencing_version','url','status'] as $key) {
            if (!is_array($current) || ($current[$key] ?? null) !== ($surface[$key] ?? null)) {
                throw new GatewayError('surface_fence_lost', 'The discarded staging page ownership changed.', 409);
            }
        }
        $source = $db->table('gateway_response_jobs')->where('id', $responseId)->find(self::SOURCE_FIELDS);
        $epoch = $db->table('gateway_task_epochs')->where('id', $surface['epoch_id'])->find();
        if (!is_array($source) || $source['task_id'] !== $surface['task_id'] || $source['surface_id'] !== $surface['id']
            || !is_array($epoch) || $epoch['task_id'] !== $surface['task_id'] || $epoch['status'] !== 'active') {
            throw new GatewayError('surface_fence_lost', 'The discarded staging source could not be verified.', 409);
        }
        $last = $db->table('gateway_task_epochs')->where('task_id', $surface['task_id'])
            ->order([['epoch_no','DESC']])->limit(1)->find('epoch_no');
        if (!is_array($last)) throw new GatewayError('storage_unavailable', 'The staging retirement epoch could not be read.', 503);
        $now = time();
        $epochId = 'epoch_' . bin2hex(random_bytes(12));
        $retiredId = 'surf_' . bin2hex(random_bytes(12));
        $released = $db->table('gateway_browser_surfaces')->where('id', $surface['id'])
            ->where('fencing_version', $surface['fencing_version'])->update(['target_id'=>null,'session_id'=>null,'url'=>'','title'=>'']);
        if (!$released || $released->rowCount() !== 1) throw new GatewayError('surface_fence_lost', 'The staging target could not be preserved.', 409);
        if (!$db->table('gateway_task_epochs')->add([
            'id'=>$epochId, 'task_id'=>$surface['task_id'], 'epoch_no'=>(int)$last['epoch_no']+1,
            'lease_key'=>$epoch['lease_key'], 'model'=>$epoch['model'], 'reasoning_effort'=>$epoch['reasoning_effort'],
            'compaction_epoch'=>$epoch['compaction_epoch'], 'status'=>'retired', 'created_at'=>$now, 'retired_at'=>$now,
        ])) throw new GatewayError('storage_unavailable', 'The staging retirement epoch could not be saved.', 503);
        $retired = array_replace($surface, ['id'=>$retiredId,'epoch_id'=>$epochId,'status'=>'retired',
            'session_id'=>null,'retired_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);
        if (!$db->table('gateway_browser_surfaces')->add($retired)) {
            throw new GatewayError('storage_unavailable', 'The discarded staging page could not be saved.', 503);
        }
        $retired['origin_surface_id'] = $surface['id'];
        self::captureUnlocked($retired, $source, $chat);
        return $retiredId;
    }

    /**
     * Lazily register only old retired rows whose physical target is still owned; no historical backfill.
     * @param string|null $taskId Optional exact task filter.
     * @return int Number of previously unregistered, still-targeted rows examined.
     * @throws GatewayError On unavailable metadata or conflicting writes.
     */
    public static function registerLegacyRetiredTargets(?string $taskId = null): int
    {
        $db = SDB(null, 'medoo', 'sqlite');
        // An anti-join avoids a fixed first page of already registered rows starving later targets.
        $query = $db->table('gateway_browser_surfaces AS surface')
            ->join('gateway_surface_retirements AS retirement','retirement.surface_id','surface.id','LEFT')
            ->where('surface.status','retired')->where('surface.target_id','<>',null)->where('retirement.surface_id',null);
        if ($taskId !== null) $query = $query->where('surface.task_id',$taskId);
        $fields = implode(',',array_map(static fn(string $field): string => 'surface.'.$field,explode(',',self::SURFACE_FIELDS)));
        $surfaces = $query->limit(100)->select($fields);
        if (!is_array($surfaces)) throw new GatewayError('storage_unavailable', 'Retired targets could not be read.', 503);
        $count = 0;
        foreach ($surfaces as $surface) {
            $existing = $db->table('gateway_surface_retirements')->where('surface_id',$surface['id'])->find('surface_id');
            if (is_array($existing)) continue;
            Schema::transaction(static function () use ($surface): void {
                $current = SDB(null,'medoo','sqlite')->table('gateway_browser_surfaces')->where('id',$surface['id'])
                    ->where('status','retired')->find(self::SURFACE_FIELDS);
                if (is_array($current) && !empty($current['target_id'])) self::captureUnlocked($current);
            });
            $count++;
        }
        return $count;
    }

    /**
     * Read pending/stopped obligations together with the exact retired surface; stopped still needs close.
     * @param string|null $taskId Optional exact task filter.
     * @return list<array> Flat snapshot, state, stable snapshot_hash and current surface metadata.
     * @throws GatewayError On unavailable state.
     */
    public static function pending(?string $taskId = null): array
    {
        self::registerLegacyRetiredTargets($taskId);
        $db = SDB(null,'medoo','sqlite');
        $query = $db->table('gateway_surface_retirements')->where('state','<>','closed');
        if ($taskId !== null) $query = $query->where('task_id',$taskId);
        $rows = $query->order([['created_at','ASC']])->limit(100)->select();
        if (!is_array($rows)) throw new GatewayError('storage_unavailable', 'Retired page stop state could not be read.', 503);
        $result = [];
        foreach ($rows as $row) {
            $surface = $db->table('gateway_browser_surfaces')->where('id',$row['surface_id'])->find(self::SURFACE_FIELDS);
            if (!is_array($surface) || $surface['status'] !== 'retired') continue;
            $snapshot = json_decode((string)$row['snapshot_json'],true);
            if (!is_array($snapshot)) throw new GatewayError('storage_unavailable', 'Retired page stop evidence is invalid.', 503);
            if (empty($surface['target_id']) && !empty($snapshot['target_id'])) {
                $destination = $db->table('gateway_browser_surfaces')->where('task_id',$row['task_id'])
                    ->where('target_id',$snapshot['target_id'])->where('status','ready')->find('id');
                if ($destination === false) throw new GatewayError('storage_unavailable','Transferred page ownership could not be read.',503);
                if (is_array($destination)) {
                    $source = array_replace($surface,['target_id'=>$snapshot['target_id'],'fencing_version'=>$snapshot['fencing_version']]);
                    // Normal polls stay read-only. Take a writer lock only for a fully
                    // proved legacy handoff, then revalidate all evidence under that lock.
                    if (is_array(self::retainedTransferProofUnlocked($source,$destination['id']))
                        && Schema::transaction(static fn(): bool => self::releaseTransferredUnlocked($source,$destination['id']))) continue;
                }
            }
            $checkpointHashes = self::checkpointControlHashes($snapshot, $surface);
            if ($checkpointHashes !== []) $snapshot['checkpoint_control_hashes'] = $checkpointHashes;
            $result[] = $snapshot + ['state'=>$row['state'], 'snapshot_hash'=>hash('sha256',$row['snapshot_json']), 'surface'=>$surface];
        }
        return $result;
    }

    /**
     * Reconcile old releases before another epoch transition can obscure their exact handoff.
     * Epoch retirement uses the same transfer proof as the browser's retirement poll.
     * @param string|null $taskId Exact transitioning task, or the browser's bounded pending batch.
     * @return int Obsolete obligations released; unknown or incomplete transfers remain untouched.
     * @throws GatewayError On unavailable metadata; the caller owns the write transaction.
     */
    public static function reconcileTransferredUnlocked(?string $taskId = null): int
    {
        $db = SDB(null,'medoo','sqlite');
        $query = $db->table('gateway_surface_retirements')->where('state','<>','closed');
        if ($taskId !== null) $query = $query->where('task_id',$taskId);
        $rows = $query->order([['created_at','ASC']])->limit(100)->select();
        if (!is_array($rows)) throw new GatewayError('storage_unavailable','Transferred page stop state could not be read.',503);
        $released = 0;
        foreach ($rows as $row) {
            $surface = $db->table('gateway_browser_surfaces')->where('id',$row['surface_id'])->find(self::SURFACE_FIELDS);
            if ($surface === false) throw new GatewayError('storage_unavailable','Transferred source page could not be read.',503);
            if (!is_array($surface) || $surface['status'] !== 'retired' || !empty($surface['target_id'])) continue;
            $snapshot = json_decode((string)$row['snapshot_json'],true);
            if (!is_array($snapshot) || empty($snapshot['target_id'])) continue;
            $destination = $db->table('gateway_browser_surfaces')->where('task_id',$row['task_id'])
                ->where('target_id',$snapshot['target_id'])->where('status','ready')->find('id');
            if ($destination === false) throw new GatewayError('storage_unavailable','Transferred page ownership could not be read.',503);
            if (!is_array($destination)) continue;
            $source = array_replace($surface,['target_id'=>$snapshot['target_id'],'fencing_version'=>$snapshot['fencing_version']]);
            if (self::releaseTransferredUnlocked($source,$destination['id'])) $released++;
        }
        return $released;
    }

    /**
     * Release an obsolete Stop obligation after an atomic transfer of the very same physical page.
     * This is neither a Stop acknowledgement nor physical closure. The old surface must have
     * relinquished its target and the same task's unclaimed destination must hold that exact
     * target/document with the next generation/fence. Call within the transfer's transaction.
     * @param array $source Original source surface before its target was cleared.
     * @param string $destinationId New logical owner, already populated by the existing transfer.
     * @return bool True if no obligation exists or that exact obligation was released; false for unproved transfers.
     * @throws GatewayError On unavailable metadata or failed conditional writes; caller rolls back the transfer.
     */
    public static function releaseTransferredUnlocked(array $source, string $destinationId): bool
    {
        $row = self::retainedTransferProofUnlocked($source,$destinationId);
        if ($row === null) return true;
        if ($row === false) return false;
        $db = SDB(null,'medoo','sqlite');
        $deleted = $db->table('gateway_surface_retirements')->where('surface_id',$source['id'])
            ->where('task_id',$source['task_id'])->where('state',$row['state'])->where('snapshot_json',$row['snapshot_json'])->delete();
        if (!$deleted || $deleted->rowCount() !== 1) throw new GatewayError('surface_fence_lost','Transferred page stop ownership changed.',409);
        if (!$db->table('gateway_task_events')->add(['task_id'=>$source['task_id'],'turn_id'=>null,'response_id'=>null,
            'surface_id'=>$source['id'],'event_type'=>'retired_page_transferred',
            'data_json'=>self::encode(['destination_surface_id'=>$destinationId,'source_epoch_id'=>$source['epoch_id'],
                'fencing_version'=>(int)$source['fencing_version']+1]),'created_at'=>time()])) {
            throw new GatewayError('storage_unavailable','Transferred page ownership receipt could not be saved.',503);
        }
        return true;
    }

    /** Read-only handoff proof: an exact obligation, null if absent, or false if ownership cannot be proved. */
    private static function retainedTransferProofUnlocked(array $source, string $destinationId): array|false|null
    {
        $db = SDB(null,'medoo','sqlite');
        $row = $db->table('gateway_surface_retirements')->where('surface_id',$source['id'])->find();
        if ($row === false) throw new GatewayError('storage_unavailable','Transferred page stop state could not be read.',503);
        if ($row === null) return null;
        $snapshot = json_decode((string)$row['snapshot_json'],true);
        if (!is_array($snapshot) || !in_array($row['state'],['pending','stopped'],true)
            || $row['task_id'] !== ($source['task_id'] ?? null)) return false;
        foreach (['task_id','epoch_id','target_id','url','generation','document_nonce','owner_incarnation','fencing_version'] as $key) {
            $value = $key === 'url' ? (self::savedUrl((string)$source['url']) ?? $source['url']) : ($source[$key] ?? '');
            if ((string)$value !== (string)($snapshot[$key] ?? '')) return false;
        }
        $old = $db->table('gateway_browser_surfaces')->where('id',$source['id'])->find(self::SURFACE_FIELDS);
        $next = $db->table('gateway_browser_surfaces')->where('id',$destinationId)->find(self::SURFACE_FIELDS);
        if ($old === false || $next === false) throw new GatewayError('storage_unavailable','Transferred page metadata could not be read.',503);
        if (!is_array($old) || !is_array($next) || $old['id'] === $next['id'] || empty($source['target_id'])
            || $old['status'] !== 'retired' || !empty($old['target_id']) || $next['status'] !== 'ready'
            || $old['task_id'] !== $source['task_id'] || $next['task_id'] !== $source['task_id']
            || $old['epoch_id'] !== $source['epoch_id'] || $next['epoch_id'] === $source['epoch_id']
            || $next['target_id'] !== $source['target_id'] || $old['document_nonce'] !== $source['document_nonce']
            || $next['document_nonce'] !== $source['document_nonce'] || $old['url'] !== $source['url'] || $next['url'] !== $source['url']
            || (int)$old['generation'] !== (int)$source['generation'] || (int)$next['generation'] !== (int)$source['generation']+1
            || (int)$old['fencing_version'] !== (int)$source['fencing_version']+1
            || (int)$next['fencing_version'] !== (int)$source['fencing_version']+1
            || $next['owner_incarnation'] !== 'unclaimed') return false;
        $sourceEpoch = $db->table('gateway_task_epochs')->where('id',$source['epoch_id'])->find('task_id,status');
        $destinationEpoch = $db->table('gateway_task_epochs')->where('id',$next['epoch_id'])->find('task_id,status');
        if ($sourceEpoch === false || $destinationEpoch === false) throw new GatewayError('storage_unavailable','Transferred page epoch could not be read.',503);
        if (($sourceEpoch['task_id'] ?? null) !== $source['task_id'] || ($sourceEpoch['status'] ?? null) !== 'retired'
            || ($destinationEpoch['task_id'] ?? null) !== $source['task_id'] || ($destinationEpoch['status'] ?? null) !== 'active') return false;
        return $row;
    }

    /**
     * Check immutable retirement evidence and reject a target or saved URL transferred to any live surface.
     * @param array $snapshot Value returned by pending/claimRecoveryTarget.
     * @return bool Whether it still owns this exact retired page, without changing the ledger.
     * @throws GatewayError On unreadable metadata.
     */
    public static function owns(array $snapshot): bool
    {
        $db = SDB(null,'medoo','sqlite');
        $row = $db->table('gateway_surface_retirements')->where('surface_id',(string)($snapshot['surface_id'] ?? ''))->find();
        if (!is_array($row) || $row['state'] === 'closed' || ($row['task_id'] ?? '') !== ($snapshot['task_id'] ?? '')
            || !hash_equals(hash('sha256',(string)$row['snapshot_json']),(string)($snapshot['snapshot_hash'] ?? ''))
            || !hash_equals(hash('sha256',(string)$row['snapshot_json']),hash('sha256',self::encode(self::persistedSnapshot($snapshot))))) return false;
        $surface = $db->table('gateway_browser_surfaces')->where('id',$row['surface_id'])->find(self::SURFACE_FIELDS);
        if (!is_array($surface) || $surface['status'] !== 'retired') return false;
        foreach (['task_id','epoch_id','target_id','url','generation','document_nonce','owner_incarnation','fencing_version'] as $key) {
            $actual = $key === 'url' ? (self::savedUrl((string)$surface['url']) ?? (string)$surface['url']) : (string)($surface[$key] ?? '');
            if ((string)$actual !== (string)($snapshot[$key] ?? '')) return false;
        }
        if (isset($snapshot['checkpoint_control_hashes'])
            && $snapshot['checkpoint_control_hashes'] !== self::checkpointControlHashes($snapshot,$surface)) return false;
        return !self::transferredUnlocked($surface,self::savedUrl((string)$snapshot['url']));
    }

    /**
     * Persist the browser's confirmed stop/idle observation before physical closure.
     * @param array $snapshot Exact evidence used by that asynchronous browser observation.
     * @return bool False when a replacement, transfer or another worker invalidated the observation.
     * @throws GatewayError On storage failure.
     */
    public static function confirmStopped(array $snapshot): bool
    {
        return Schema::transaction(static function () use ($snapshot): bool {
            if (!self::owns($snapshot)) return false;
            $db = SDB(null,'medoo','sqlite');
            $row = $db->table('gateway_surface_retirements')->where('surface_id',$snapshot['surface_id'])->find('state');
            if (($row['state'] ?? '') === 'stopped') return true;
            $changed = $db->table('gateway_surface_retirements')->where('surface_id',$snapshot['surface_id'])
                ->where('state','pending')->update(['state'=>'stopped','updated_at'=>time()]);
            if ($changed === false) throw new GatewayError('storage_unavailable','Retired page stop receipt could not be saved.',503);
            return $changed && $changed->rowCount() === 1;
        });
    }

    /**
     * Bind a newly created blank target solely to recovering the same old saved chat for stopping.
     * The browser caller must already have observed the former target absent; no navigation is performed here.
     * @param array $snapshot Current immutable retirement evidence.
     * @param string $newTargetId Newly created about:blank target, not an existing task's page.
     * @return array|null Updated snapshot with a new document/fence, or null on lost ownership.
     * @throws GatewayError On storage failure.
     */
    public static function claimRecoveryTarget(array $snapshot, string $newTargetId): ?array
    {
        if ($newTargetId === '' || strlen($newTargetId) > 512 || ($snapshot['chat_mode'] ?? '') !== 'regular'
            || self::savedUrl((string)($snapshot['url'] ?? '')) === null) return null;
        return Schema::transaction(static function () use ($snapshot,$newTargetId): ?array {
            if (!self::owns($snapshot)) return null;
            $db = SDB(null,'medoo','sqlite');
            $state = $db->table('gateway_surface_retirements')->where('surface_id',$snapshot['surface_id'])->find('state');
            if (($state['state'] ?? '') !== 'pending') return null;
            $used = $db->table('gateway_browser_surfaces')->where('target_id',$newTargetId)->find('id');
            if (is_array($used)) return null;
            $next = self::persistedSnapshot($snapshot);
            $next['target_id'] = $newTargetId;
            $next['document_nonce'] = bin2hex(random_bytes(16));
            $next['generation']++;
            $next['fencing_version']++;
            $next['accepted_initial'] = false;
            $next['baseline_users'] = [];
            $next['recovered_target'] = true;
            $changed = $db->table('gateway_browser_surfaces')->where('id',$next['surface_id'])->where('status','retired')
                ->where('fencing_version',$snapshot['fencing_version'])->update([
                    'target_id'=>$newTargetId,'session_id'=>null,'document_nonce'=>$next['document_nonce'],
                    'generation'=>$next['generation'],'fencing_version'=>$next['fencing_version'],'updated_at'=>time(),
                ]);
            if (!$changed || $changed->rowCount() !== 1) throw new GatewayError('surface_fence_lost','Retired recovery target ownership changed.',409);
            $json = self::encode($next);
            $written = $db->table('gateway_surface_retirements')->where('surface_id',$next['surface_id'])
                ->where('snapshot_json',self::encode(self::persistedSnapshot($snapshot)))->update(['snapshot_json'=>$json,'updated_at'=>time()]);
            if (!$written || $written->rowCount() !== 1) throw new GatewayError('surface_fence_lost','Retired recovery evidence changed.',409);
            $row = $db->table('gateway_surface_retirements')->where('surface_id',$next['surface_id'])->find('state');
            $surface = $db->table('gateway_browser_surfaces')->where('id',$next['surface_id'])->find(self::SURFACE_FIELDS);
            return $next + ['state'=>$row['state'],'snapshot_hash'=>hash('sha256',$json),'surface'=>$surface];
        });
    }

    /**
     * Record closure only after a confirmed stop, clearing the original target atomically.
     * @param array $snapshot Exact stopped page evidence; target may already have disappeared externally.
     * @return bool False for pending, reassigned or stale snapshots.
     * @throws GatewayError On storage failure.
     */
    public static function closed(array $snapshot): bool
    {
        return Schema::transaction(static function () use ($snapshot): bool {
            if (!self::owns($snapshot)) return false;
            $db = SDB(null,'medoo','sqlite');
            $changed = $db->table('gateway_surface_retirements')->where('surface_id',$snapshot['surface_id'])
                ->where('state','stopped')->update(['state'=>'closed','updated_at'=>time()]);
            if ($changed === false) throw new GatewayError('storage_unavailable','Retired page closure could not be saved.',503);
            if (!$changed || $changed->rowCount() !== 1) return false;
            TaskLedger::targetClosed((string)$snapshot['surface_id']);
            return true;
        });
    }

    /**
     * Reconcile an absent temporary staging page that never acquired executable authority.
     * The browser caller must first confirm this exact target absent in a successful inventory.
     * This records physical absence plus inert-submission proof, never a fabricated Stop ACK.
     * @param array $snapshot Exact owned retirement with its original source/token witnesses.
     * @return bool Whether the inert obligation was atomically closed; uncertain/executed pages stay pending.
     * @throws GatewayError If the proof or closure receipt cannot be read or persisted.
     */
    public static function closeMissingInertStage(array $snapshot): bool
    {
        if (($snapshot['chat_mode'] ?? '') !== 'temporary' || empty($snapshot['transaction_hashes'])
            || empty($snapshot['source_response_id'])) return false;
        return Schema::transaction(static function () use ($snapshot): bool {
            if (!self::owns($snapshot)) return false;
            $db = SDB(null,'medoo','sqlite');
            $job = $db->table('gateway_response_jobs')->where('id',$snapshot['source_response_id'])->find(
                'id,task_id,surface_id,status,operation_kind,durable_turn_id,mcp_binding_id,submit_intent_at,accepted_at,output_started,output_text');
            if (!is_array($job) || $job['task_id'] !== $snapshot['task_id'] || $job['operation_kind'] !== 'response'
                || $job['surface_id'] !== ($snapshot['origin_surface_id'] ?? $snapshot['surface_id'])
                || $job['status'] !== 'failed' || $job['submit_intent_at'] !== null || $job['accepted_at'] !== null
                || (int)$job['output_started'] !== 0 || $job['output_text'] !== '' || empty($job['mcp_binding_id'])) return false;
            $turn = $db->table('gateway_task_turns')->where('id',$job['durable_turn_id'])->find();
            $binding = $db->table('gateway_mcp_bindings')->where('id',$job['mcp_binding_id'])->find();
            if (!is_array($turn) || !is_array($binding) || $turn['task_id'] !== $snapshot['task_id']
                || $turn['surface_id'] !== $job['surface_id'] || !in_array($turn['status'],['failed','cancelled'],true)
                || $turn['submit_intent_at'] !== null || $turn['submitted_at'] !== null || $turn['committed_at'] !== null
                || $binding['task_id'] !== $snapshot['task_id'] || $binding['turn_id'] !== $turn['id']
                || $binding['current_response_id'] !== $job['id'] || !in_array($binding['status'],['awaiting_start','revoked'],true)
                || $binding['sent_at'] !== null || $binding['started_at'] !== null || (int)$binding['activity_revision'] !== 0
                || !in_array($binding['token_hash'],$snapshot['turn_token_hashes'] ?? [],true)) return false;
            $counts = [
                $db->table('gateway_tool_invocations')->where('turn_id',$turn['id'])->count(),
                $db->table('gateway_mcp_activities')->where('binding_id',$binding['id'])->count(),
                $db->table('gateway_web_messages')->where('response_id',$job['id'])
                    ->where('kind','NOT IN',['stage','stage_ack','stage_ack_repair'])->count(),
                $db->table('gateway_web_messages')->where('response_id',$job['id'])->where('direction','outbound')->where('kind','stage')->count(),
            ];
            foreach ($counts as $count) if (!is_numeric($count)) throw new GatewayError('storage_unavailable','Inert staging evidence could not be read.',503);
            if ((int)$counts[0] !== 0 || (int)$counts[1] !== 0 || (int)$counts[2] !== 0 || (int)$counts[3] < 1) return false;
            $changed = $db->table('gateway_surface_retirements')->where('surface_id',$snapshot['surface_id'])
                ->where('state','pending')->where('snapshot_json',self::encode(self::persistedSnapshot($snapshot)))
                ->update(['state'=>'closed','updated_at'=>time()]);
            if (!$changed || $changed->rowCount() !== 1) return false;
            TaskLedger::targetClosed($snapshot['surface_id']);
            if (!$db->table('gateway_task_events')->add(['task_id'=>$snapshot['task_id'],'turn_id'=>$turn['id'],
                'response_id'=>$job['id'],'surface_id'=>$snapshot['surface_id'],'event_type'=>'retired_inert_stage_absent',
                'data_json'=>self::encode(['reason'=>'temporary_staging_without_executable_commit']),'created_at'=>time()])) {
                throw new GatewayError('storage_unavailable','Inert staging closure could not be saved.',503);
            }
            return true;
        });
    }

    /**
     * Close an absent temporary page whose exact native task already completed before retirement.
     * Caller must have a successful inventory with neither its target nor the same chat URL present.
     * This records absence after completion, never a fabricated browser Stop acknowledgement.
     * @param array $snapshot Immutable pending retirement plus the current ownership witness.
     * @return bool False for live, uncertain, unacknowledged, reassigned or non-temporary work.
     * @throws GatewayError On unavailable or unpersistable retirement evidence.
     */
    public static function closeMissingCompletedPage(array $snapshot): bool
    {
        if (($snapshot['chat_mode'] ?? '') !== 'temporary' || empty($snapshot['source_response_id'])
            || preg_match('~\Ahttps://chatgpt\.com/c/[a-f0-9-]{16,}(?:[/?#].*)?\z~i', (string)($snapshot['url'] ?? '')) !== 1) return false;
        return Schema::transaction(static function () use ($snapshot): bool {
            if (!self::owns($snapshot)) return false;
            $db = SDB(null,'medoo','sqlite');
            $retirement = $db->table('gateway_surface_retirements')->where('surface_id',$snapshot['surface_id'])->find('state,created_at');
            if (!is_array($retirement) || $retirement['state'] !== 'pending') return false;
            $sourceSurface = $snapshot['origin_surface_id'] ?? $snapshot['surface_id'];
            $source = $db->table('gateway_response_jobs')->where('id',$snapshot['source_response_id'])
                ->find('task_id,surface_id,durable_turn_id,mcp_binding_id,submit_intent_at');
            $final = $db->table('gateway_response_jobs')->where('surface_id',$sourceSurface)
                ->order([['rowid','DESC']])->limit(1)->find('id,task_id,status,operation_kind,round_kind,terminal_type,durable_turn_id,mcp_binding_id,output_text,finished_at');
            if (!is_array($source) || !is_array($final) || $source['task_id'] !== $snapshot['task_id']
                || $source['surface_id'] !== $sourceSurface || empty($source['submit_intent_at'])
                || $final['task_id'] !== $snapshot['task_id'] || $final['status'] !== 'completed'
                || $final['operation_kind'] !== 'response' || $final['round_kind'] !== 'text'
                || $final['terminal_type'] !== 'response.completed' || $final['output_text'] === ''
                || empty($final['finished_at']) || (int)$final['finished_at'] > (int)$retirement['created_at']
                || $source['durable_turn_id'] !== $final['durable_turn_id']
                || $source['mcp_binding_id'] !== $final['mcp_binding_id']) return false;
            $turns = $db->table('gateway_task_turns')->where('surface_id',$sourceSurface)->select('id,task_id,status,response_id');
            if (!is_array($turns) || $turns === []) return false;
            $finalTurn = null;
            foreach ($turns as $turn) {
                if ($turn['task_id'] !== $snapshot['task_id'] || $turn['status'] !== 'committed') return false;
                if ($turn['id'] === $final['durable_turn_id']) $finalTurn = $turn;
            }
            if (!is_array($finalTurn) || $finalTurn['response_id'] !== $final['id']) return false;
            $bindings = $db->table('gateway_mcp_bindings')->where('turn_id','IN',array_column($turns,'id'))
                ->select('id,task_id,status,token_hash,current_response_id,final_answer');
            if (!is_array($bindings) || $bindings === []) return false;
            $finalBinding = null;
            foreach ($bindings as $binding) {
                if ($binding['task_id'] !== $snapshot['task_id'] || $binding['status'] !== 'completed') return false;
                if ($binding['id'] === $final['mcp_binding_id']) $finalBinding = $binding;
            }
            if (!is_array($finalBinding) || $finalBinding['current_response_id'] !== $final['id']
                || $finalBinding['final_answer'] !== $final['output_text']
                || !in_array($finalBinding['token_hash'],$snapshot['turn_token_hashes'] ?? [],true)) return false;
            $bindingIds = array_column($bindings,'id');
            $counts = [
                $db->table('gateway_response_jobs')->where('surface_id',$sourceSurface)->where('status','IN',['queued','running'])->count(),
                $db->table('gateway_tool_invocations')->where('binding_id','IN',$bindingIds)->where('status','<>','completed')->count(),
                $db->table('gateway_tool_invocations')->where('binding_id','IN',$bindingIds)->where('result_delivered_at',null)->count(),
                $db->table('gateway_tool_invocations')->where('binding_id','IN',$bindingIds)->where('result_json',null)->count(),
                $db->table('gateway_mcp_activities')->where('binding_id','IN',$bindingIds)->where('status','active')->count(),
            ];
            foreach ($counts as $count) {
                if (!is_numeric($count)) throw new GatewayError('storage_unavailable','Completed page state could not be read.',503);
                if ((int)$count !== 0) return false;
            }
            $changed = $db->table('gateway_surface_retirements')->where('surface_id',$snapshot['surface_id'])
                ->where('state','pending')->where('snapshot_json',self::encode(self::persistedSnapshot($snapshot)))
                ->update(['state'=>'closed','updated_at'=>time()]);
            if (!$changed || $changed->rowCount() !== 1) return false;
            TaskLedger::targetClosed($snapshot['surface_id']);
            if (!$db->table('gateway_task_events')->add(['task_id'=>$snapshot['task_id'],'turn_id'=>$finalTurn['id'],
                'response_id'=>$final['id'],'surface_id'=>$snapshot['surface_id'],'event_type'=>'retired_completed_page_absent',
                'data_json'=>self::encode(['reason'=>'committed_task_and_confirmed_absence']),'created_at'=>time()])) {
                throw new GatewayError('storage_unavailable','Completed page absence could not be saved.',503);
            }
            return true;
        });
    }

    /**
     * Reconcile a missing temporary checkpoint document before any resumed message was sent.
     * Caller must successfully inventory both the original target and every page for its chat ID.
     * The consumed summary and all source receipts must be settled; this does not fabricate Stop.
     * @return bool Whether this exact pending obligation was closed from confirmed absence.
     * @throws GatewayError On unavailable or unpersistable retirement evidence.
     */
    public static function closeMissingPendingCheckpointPage(array $snapshot): bool
    {
        if (($snapshot['chat_mode'] ?? '') !== 'temporary' || !self::owns($snapshot)
            || self::missingCheckpointPageProofUnlocked($snapshot) === null) return false;
        // Ineligible polling stays read-only; eligible evidence is rechecked under the writer fence.
        return Schema::transaction(static function () use ($snapshot): bool {
            if (!self::owns($snapshot)) return false;
            $source = self::missingCheckpointPageProofUnlocked($snapshot);
            if ($source === null) return false;
            $db = SDB(null,'medoo','sqlite');
            $changed = $db->table('gateway_surface_retirements')->where('surface_id',$snapshot['surface_id'])
                ->where('state','pending')->where('snapshot_json',self::encode(self::persistedSnapshot($snapshot)))
                ->update(['state'=>'closed','updated_at'=>time()]);
            if (!$changed || $changed->rowCount() !== 1) return false;
            TaskLedger::targetClosed($snapshot['surface_id']);
            if (!$db->table('gateway_task_events')->add(['task_id'=>$snapshot['task_id'],'turn_id'=>$source['durable_turn_id'],
                'response_id'=>$source['id'],'surface_id'=>$snapshot['surface_id'],'event_type'=>'retired_checkpoint_page_absent',
                'data_json'=>self::encode(['reason'=>'consumed_checkpoint_without_resume_submission']),'created_at'=>time()])) {
                throw new GatewayError('storage_unavailable','Checkpoint page absence could not be saved.',503);
            }
            return true;
        });
    }

    /** Read-only evidence, rechecked inside the closure transaction; never loads archived contexts. */
    private static function missingCheckpointPageProofUnlocked(array $snapshot): ?array
    {
        $db = SDB(null,'medoo','sqlite');
        $surface = $db->table('gateway_browser_surfaces')->where('id',$snapshot['surface_id'])->find(self::SURFACE_FIELDS);
        if (!is_array($surface) || self::checkpointControlHashes($snapshot,$surface) === []) return null;
        $retirement = $db->table('gateway_surface_retirements')->where('surface_id',$snapshot['surface_id'])->find('state,created_at');
        $source = $db->table('gateway_response_jobs')->where('id',$snapshot['source_response_id'])
            ->find('id,status,durable_turn_id,budget_json,created_at,transport_stage_sent,output_started,output_text');
        if (!is_array($retirement) || $retirement['state'] !== 'pending' || !is_array($source) || $source['status'] !== 'failed'
            || (int)$source['created_at'] > (int)$retirement['created_at'] || (int)$source['transport_stage_sent'] !== 0
            || (int)$source['output_started'] !== 0 || $source['output_text'] !== '') return null;
        $proof = json_decode($source['budget_json'],true)['postcompact_page'];
        $bindings = $db->table('gateway_response_jobs')->where('surface_id','IN',[$surface['id'],$proof['source_surface_id']])
            ->where('mcp_binding_id','<>',null)->select('mcp_binding_id');
        if (!is_array($bindings)) throw new GatewayError('storage_unavailable','Checkpoint source capabilities could not be read.',503);
        $counts = [$db->table('gateway_response_jobs')->where('surface_id',$proof['source_surface_id'])
            ->where('status','IN',['queued','running'])->count()];
        $bindingIds = array_values(array_unique(array_column($bindings,'mcp_binding_id')));
        if ($bindingIds !== []) {
            $counts[] = $db->table('gateway_tool_invocations')->where('binding_id','IN',$bindingIds)->where('status','<>','completed')->count();
            $counts[] = $db->table('gateway_tool_invocations')->where('binding_id','IN',$bindingIds)->where('result_delivered_at',null)->count();
            $counts[] = $db->table('gateway_tool_invocations')->where('binding_id','IN',$bindingIds)->where('result_json',null)->count();
            $counts[] = $db->table('gateway_mcp_activities')->where('binding_id','IN',$bindingIds)->where('status','active')->count();
        }
        foreach ($counts as $count) {
            if (!is_numeric($count)) throw new GatewayError('storage_unavailable','Checkpoint source work could not be read.',503);
            if ((int)$count !== 0) return null;
        }
        return $source;
    }

    /** Keep ephemeral caller fields out of the immutable JSON used for compare-and-swap. */
    private static function persistedSnapshot(array $snapshot): array
    {
        unset($snapshot['state'],$snapshot['snapshot_hash'],$snapshot['surface'],$snapshot['checkpoint_control_hashes']);
        return $snapshot;
    }

    /**
     * Derive a read-only summary-message witness for an unsent retained checkpoint lease.
     * Old snapshots pin a new host token that never reached the page. Use only the consumed
     * handoff's exact hash, verified against its committed checkpoint and original document.
     * No snapshot, token, receipt, Stop state or task ownership is changed by this upgrade path.
     * @return list<string> Verified control hashes, or empty for any unproved ancestry/activity.
     */
    private static function checkpointControlHashes(array $snapshot, array $surface): array
    {
        if (($snapshot['chat_mode'] ?? '') !== 'temporary' || empty($snapshot['source_response_id'])
            || $surface['status'] !== 'retired') return [];
        $db = SDB(null,'medoo','sqlite');
        $source = $db->table('gateway_response_jobs')->where('id',$snapshot['source_response_id'])->find(
            'id,task_id,surface_id,epoch_id,operation_kind,request_hash,budget_json,compaction_epoch,continuation_source_response_id,submit_intent_at,accepted_at,mcp_binding_id');
        if (!is_array($source) || $source['task_id'] !== $snapshot['task_id']
            || $source['operation_kind'] !== 'response'
            || $source['surface_id'] !== $surface['id'] || $source['epoch_id'] !== $surface['epoch_id']
            || $source['submit_intent_at'] !== null || $source['accepted_at'] !== null) return [];
        $proof = json_decode((string)$source['budget_json'],true)['postcompact_page'] ?? null;
        if (!is_array($proof) || array_diff(['source_response_id','source_surface_id','source_epoch_id','checkpoint_id','fencing_version'],array_keys($proof)) !== []
            || ($proof['request_hash'] ?? '') !== $source['request_hash']
            || ($proof['source_response_id'] ?? '') !== $source['continuation_source_response_id']
            || ($proof['target_id'] ?? '') !== $surface['target_id']
            || ($proof['document_nonce'] ?? '') !== $surface['document_nonce']
            || (int)$surface['fencing_version'] <= (int)($proof['fencing_version'] ?? 0)) return [];
        $compact = $db->table('gateway_response_jobs')->where('id',$proof['source_response_id'])->find(
            'id,task_id,surface_id,epoch_id,status,operation_kind,submitted_document_nonce,compact_id');
        $origin = $db->table('gateway_browser_surfaces')->where('id',$proof['source_surface_id'])->find('task_id,epoch_id,status,target_id,document_nonce');
        $checkpoint = $db->table('gateway_context_checkpoints')->where('id',$proof['checkpoint_id'])->find();
        $handoff = $db->table('gateway_compaction_handoffs')->where('compact_response_id',$proof['source_response_id'])
            ->find('checkpoint_id,task_id,state,token_hash,accepted_at,consumed_at');
        if (!is_array($compact) || $compact['task_id'] !== $snapshot['task_id'] || $compact['status'] !== 'completed'
            || $compact['operation_kind'] !== 'compact_v2' || $compact['surface_id'] !== $proof['source_surface_id']
            || $compact['epoch_id'] !== $proof['source_epoch_id'] || $compact['compact_id'] !== $proof['checkpoint_id']
            || $compact['submitted_document_nonce'] !== $surface['document_nonce']
            || !is_array($origin) || $origin['status'] !== 'retired' || $origin['target_id'] !== null
            || $origin['task_id'] !== $snapshot['task_id'] || $origin['epoch_id'] !== $proof['source_epoch_id']
            || $origin['document_nonce'] !== $surface['document_nonce']
            || !is_array($checkpoint) || $checkpoint['response_id'] !== $compact['id'] || $checkpoint['task_id'] !== $snapshot['task_id']
            || !ContextCompaction::matchesCommittedEpoch($checkpoint,$source['compaction_epoch'])
            || !is_array($handoff) || $handoff['state'] !== 'consumed' || empty($handoff['accepted_at']) || empty($handoff['consumed_at'])
            || $handoff['task_id'] !== $snapshot['task_id'] || $handoff['checkpoint_id'] !== $checkpoint['id']
            || preg_match('/\A[a-f0-9]{64}\z/D',(string)$handoff['token_hash']) !== 1) return [];
        $submitted = $db->table('gateway_response_jobs')->where('surface_id',$surface['id'])->where('submit_intent_at','<>',null)->count();
        if (!is_numeric($submitted)) throw new GatewayError('storage_unavailable','Checkpoint page submissions could not be read.',503);
        if ((int)$submitted !== 0) return [];
        if (!empty($source['mcp_binding_id'])) {
            $binding = $db->table('gateway_mcp_bindings')->where('id',$source['mcp_binding_id'])->find('status,sent_at,started_at');
            $calls = $db->table('gateway_tool_invocations')->where('binding_id',$source['mcp_binding_id'])->count();
            $active = $db->table('gateway_mcp_activities')->where('binding_id',$source['mcp_binding_id'])->where('status','active')->count();
            if (!is_numeric($calls) || !is_numeric($active)) throw new GatewayError('storage_unavailable','Checkpoint page tools could not be read.',503);
            if (!is_array($binding) || !in_array($binding['status'],['awaiting_start','revoked'],true)
                || $binding['sent_at'] !== null || $binding['started_at'] !== null || (int)$calls !== 0 || (int)$active !== 0) return [];
        }
        return [(string)$handoff['token_hash']];
    }

    /** Serialize only controlled retirement metadata, never context or plaintext capability tokens. */
    private static function encode(array $snapshot): string
    {
        return json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }

    /** A submission intent is enough to require stop verification; failed acceptance is not proof of no send. */
    private static function wasSubmitted(?array $source): bool
    {
        return is_array($source) && ((int)($source['submit_intent_at'] ?? 0) > 0 || (int)($source['accepted_at'] ?? 0) > 0
            || (int)($source['transport_stage_sent'] ?? 0) > 0);
    }

    /** Normalize a saved ChatGPT conversation URL; root and unrelated hosts cannot be recovered. */
    private static function savedUrl(string $url): ?string
    {
        if (preg_match('~[?&]temporary-chat=true(?:[&#]|$)~i',$url)) return null;
        return preg_match('~\Ahttps://chatgpt\.com/c/([a-f0-9-]{16,})(?:[/?#].*)?\z~i',$url,$match)
            ? 'https://chatgpt.com/c/'.strtolower($match[1]) : null;
    }

    /** Reject logical checkpoint transfers and any target/regular URL now owned by a non-retired surface. */
    private static function transferredUnlocked(array $surface, ?string $savedUrl): bool
    {
        $db = SDB(null,'medoo','sqlite');
        $target = (string)($surface['target_id'] ?? '');
        if ($target !== '') {
            $other = $db->table('gateway_browser_surfaces')->where('target_id',$target)->where('id','<>',$surface['id'])
                ->where('status','<>','retired')->find('id');
            if (is_array($other)) return true;
            if ($other === false) throw new GatewayError('storage_unavailable','Retired target ownership could not be read.',503);
        }
        if ($savedUrl !== null) {
            $others = $db->table('gateway_browser_surfaces')->where('status','<>','retired')->where('url','LIKE',$savedUrl.'%')
                ->where('id','<>',$surface['id'])->select('url');
            if (!is_array($others)) throw new GatewayError('storage_unavailable','Saved page ownership could not be read.',503);
            foreach ($others as $other) if (self::savedUrl((string)$other['url']) === $savedUrl) return true;
        }
        return false;
    }
}
