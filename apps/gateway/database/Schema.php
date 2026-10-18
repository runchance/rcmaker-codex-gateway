<?php
declare(strict_types=1);
namespace app\gateway\database;

use RC\Config;
use app\gateway\model\GatewayError;

/** Owns SQLite schema and short writer transactions through the framework connection, without a separate PDO. */
final class Schema
{
    private static bool $ready = false;
    private const WRITER_WAIT_MILLISECONDS = 5000;
    private const WRITER_LOCK_ATTEMPTS = 3;

    /** @return array<int,string> Immutable migration SQL; hashes cover logical SQL, not package bytes. */
    public static function migrationOne(): array
    {
        return [
            'CREATE TABLE gateway_network_revisions (id INTEGER PRIMARY KEY AUTOINCREMENT, mode TEXT NOT NULL CHECK(mode IN (\'unconfigured\',\'direct\',\'manual\')), protocol TEXT NOT NULL, host TEXT NOT NULL, port INTEGER NOT NULL, username TEXT NOT NULL, protected_password TEXT, restored_from INTEGER REFERENCES gateway_network_revisions(id), created_at INTEGER NOT NULL)',
            'CREATE TABLE gateway_network_state (id INTEGER PRIMARY KEY CHECK(id=1), saved_revision INTEGER NOT NULL REFERENCES gateway_network_revisions(id), applied_php_revision INTEGER REFERENCES gateway_network_revisions(id), last_apply_revision INTEGER, last_apply_error TEXT, probe_heartbeat INTEGER NOT NULL DEFAULT 0)',
            'CREATE TABLE gateway_network_tests (id INTEGER PRIMARY KEY AUTOINCREMENT, revision INTEGER NOT NULL REFERENCES gateway_network_revisions(id), target_id TEXT NOT NULL, status TEXT NOT NULL, result_json TEXT, created_at INTEGER NOT NULL, started_at INTEGER, finished_at INTEGER)',
            'CREATE INDEX gateway_network_tests_pending ON gateway_network_tests(status, id)',
            "INSERT INTO gateway_network_revisions(mode,protocol,host,port,username,created_at) VALUES ('unconfigured','http','',0,'',0)",
            'INSERT INTO gateway_network_state(id,saved_revision) VALUES (1,1)',
        ];
    }

    /** @return array<int,string> Step 03 browser workspace schema. */
    public static function migrationTwo(): array
    {
        return [
            "CREATE TABLE gateway_browser_state (id INTEGER PRIMARY KEY CHECK(id=1), status TEXT NOT NULL DEFAULT 'stopped', browser_kind TEXT, executable TEXT, browser_pid INTEGER, debug_port INTEGER, network_revision INTEGER REFERENCES gateway_network_revisions(id), active_target TEXT, session_id TEXT, surface_generation INTEGER NOT NULL DEFAULT 1, document_nonce TEXT, lease_mode TEXT NOT NULL DEFAULT 'manual' CHECK(lease_mode IN ('manual','automation')), url TEXT NOT NULL DEFAULT '', title TEXT NOT NULL DEFAULT '', tabs_json TEXT NOT NULL DEFAULT '[]', viewport_width INTEGER NOT NULL DEFAULT 1280, viewport_height INTEGER NOT NULL DEFAULT 720, screenshot_sequence INTEGER NOT NULL DEFAULT 0, screenshot_mime TEXT, worker_heartbeat INTEGER NOT NULL DEFAULT 0, last_error TEXT, owner_identity TEXT, launch_nonce TEXT, updated_at INTEGER NOT NULL DEFAULT 0)",
            "INSERT INTO gateway_browser_state(id,status,lease_mode,updated_at) VALUES (1,'stopped','manual',0)",
            "CREATE TABLE gateway_browser_commands (id INTEGER PRIMARY KEY AUTOINCREMENT, action TEXT NOT NULL, surface_generation INTEGER NOT NULL, document_nonce TEXT, payload_json TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'queued', result_json TEXT, created_at INTEGER NOT NULL, started_at INTEGER, finished_at INTEGER)",
            'CREATE INDEX gateway_browser_commands_pending ON gateway_browser_commands(status,id)',
        ];
    }

    /** @return array<int,string> Step 04 ChatGPT page capability cache and Responses text jobs. */
    public static function migrationThree(): array
    {
        return [
            'ALTER TABLE gateway_browser_state ADD COLUMN chatgpt_json TEXT',
            'ALTER TABLE gateway_browser_state ADD COLUMN chatgpt_observed_at INTEGER NOT NULL DEFAULT 0',
            "CREATE TABLE gateway_response_jobs (id TEXT PRIMARY KEY, item_id TEXT NOT NULL UNIQUE, idempotency_key TEXT UNIQUE, request_hash TEXT NOT NULL, model TEXT NOT NULL, prompt TEXT NOT NULL, status TEXT NOT NULL CHECK(status IN ('queued','running','completed','failed')), command_id INTEGER REFERENCES gateway_browser_commands(id), accepted_at INTEGER, output_started INTEGER NOT NULL DEFAULT 0, output_text TEXT NOT NULL DEFAULT '', response_json TEXT, error_json TEXT, sequence_next INTEGER NOT NULL DEFAULT 0, terminal_type TEXT, created_at INTEGER NOT NULL, started_at INTEGER, finished_at INTEGER)",
            'CREATE INDEX gateway_response_jobs_status ON gateway_response_jobs(status,created_at)',
            'CREATE TABLE gateway_response_events (response_id TEXT NOT NULL REFERENCES gateway_response_jobs(id) ON DELETE CASCADE, sequence_number INTEGER NOT NULL, event_json TEXT NOT NULL, created_at INTEGER NOT NULL, PRIMARY KEY(response_id,sequence_number))',
            'CREATE INDEX gateway_response_events_read ON gateway_response_events(response_id,sequence_number)',
        ];
    }

    /** @return array<int,string> Setup lifecycle plus native Codex browser-lease identity carried by Step 04 jobs. */
    public static function migrationFour(): array
    {
        return [
            'ALTER TABLE gateway_browser_state ADD COLUMN auto_start INTEGER NOT NULL DEFAULT 0 CHECK(auto_start IN (0,1))',
            'ALTER TABLE gateway_browser_state ADD COLUMN setup_completed INTEGER NOT NULL DEFAULT 0 CHECK(setup_completed IN (0,1))',
            'ALTER TABLE gateway_response_jobs ADD COLUMN browser_owner_key TEXT',
            'ALTER TABLE gateway_response_jobs ADD COLUMN browser_lease_key TEXT',
            'ALTER TABLE gateway_response_jobs ADD COLUMN thread_id TEXT',
            'ALTER TABLE gateway_response_jobs ADD COLUMN turn_id TEXT',
            'ALTER TABLE gateway_response_jobs ADD COLUMN reasoning_effort TEXT',
            'ALTER TABLE gateway_response_jobs ADD COLUMN compaction_epoch TEXT',
            'CREATE INDEX gateway_response_jobs_browser_owner ON gateway_response_jobs(browser_owner_key,created_at)',
            'CREATE INDEX gateway_response_jobs_browser_lease ON gateway_response_jobs(browser_lease_key,created_at)',
        ];
    }

    /** @return array<int,string> Step 05 durable task ledger, browser surface pool and recovery identity. */
    public static function migrationFive(): array
    {
        return [
            "CREATE TABLE gateway_tasks (id TEXT PRIMARY KEY, client_id TEXT NOT NULL, thread_id TEXT, branch_id TEXT NOT NULL, owner_key TEXT NOT NULL, status TEXT NOT NULL CHECK(status IN ('active','completed','cancelled','needs_attention')), current_epoch_id TEXT, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL, cancelled_at INTEGER, UNIQUE(client_id,owner_key,branch_id))",
            "CREATE TABLE gateway_task_epochs (id TEXT PRIMARY KEY, task_id TEXT NOT NULL REFERENCES gateway_tasks(id) ON DELETE CASCADE, epoch_no INTEGER NOT NULL, lease_key TEXT NOT NULL, model TEXT NOT NULL, reasoning_effort TEXT, compaction_epoch TEXT NOT NULL, status TEXT NOT NULL CHECK(status IN ('active','retired','needs_attention')), created_at INTEGER NOT NULL, retired_at INTEGER, UNIQUE(task_id,epoch_no))",
            'CREATE INDEX gateway_task_epochs_task ON gateway_task_epochs(task_id,status,epoch_no)',
            "CREATE TABLE gateway_browser_surfaces (id TEXT PRIMARY KEY, task_id TEXT NOT NULL REFERENCES gateway_tasks(id) ON DELETE CASCADE, epoch_id TEXT NOT NULL REFERENCES gateway_task_epochs(id) ON DELETE CASCADE, lease_key TEXT NOT NULL, target_id TEXT UNIQUE, session_id TEXT, status TEXT NOT NULL CHECK(status IN ('reserved','attaching','ready','busy','needs_attention','retired')), generation INTEGER NOT NULL DEFAULT 1, document_nonce TEXT NOT NULL, owner_incarnation TEXT NOT NULL, fencing_version INTEGER NOT NULL DEFAULT 1, url TEXT NOT NULL DEFAULT '', title TEXT NOT NULL DEFAULT '', last_observed_at INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL, retired_at INTEGER, UNIQUE(task_id,epoch_id))",
            'CREATE INDEX gateway_browser_surfaces_pool ON gateway_browser_surfaces(status,updated_at)',
            "CREATE TABLE gateway_task_turns (id TEXT PRIMARY KEY, task_id TEXT NOT NULL REFERENCES gateway_tasks(id) ON DELETE CASCADE, epoch_id TEXT NOT NULL REFERENCES gateway_task_epochs(id) ON DELETE CASCADE, surface_id TEXT NOT NULL REFERENCES gateway_browser_surfaces(id), client_turn_id TEXT, request_hash TEXT NOT NULL, generation_id TEXT NOT NULL UNIQUE, response_id TEXT UNIQUE, status TEXT NOT NULL CHECK(status IN ('queued','submit_intent','submitted','running','committed','failed','cancelled','needs_attention')), owner_incarnation TEXT NOT NULL, fencing_version INTEGER NOT NULL DEFAULT 1, deadline_at INTEGER NOT NULL, submit_intent_at INTEGER, submitted_at INTEGER, committed_at INTEGER, retired_at INTEGER, error_code TEXT, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL)",
            'CREATE UNIQUE INDEX gateway_task_turns_client_identity ON gateway_task_turns(task_id,client_turn_id) WHERE client_turn_id IS NOT NULL',
            'CREATE INDEX gateway_task_turns_active ON gateway_task_turns(status,updated_at)',
            "CREATE TABLE gateway_task_events (id INTEGER PRIMARY KEY AUTOINCREMENT, task_id TEXT NOT NULL REFERENCES gateway_tasks(id) ON DELETE CASCADE, turn_id TEXT, response_id TEXT, surface_id TEXT, event_type TEXT NOT NULL, data_json TEXT NOT NULL DEFAULT '{}', created_at INTEGER NOT NULL)",
            'CREATE INDEX gateway_task_events_timeline ON gateway_task_events(task_id,id)',
            "CREATE TABLE gateway_tool_invocations (id TEXT PRIMARY KEY, task_id TEXT NOT NULL REFERENCES gateway_tasks(id) ON DELETE CASCADE, turn_id TEXT NOT NULL REFERENCES gateway_task_turns(id) ON DELETE CASCADE, response_id TEXT, call_id TEXT NOT NULL, request_hash TEXT NOT NULL, status TEXT NOT NULL CHECK(status IN ('registered','delivered','completed','unknown','cancelled')), result_json TEXT, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL, UNIQUE(task_id,turn_id,call_id))",
            'ALTER TABLE gateway_response_jobs ADD COLUMN task_id TEXT REFERENCES gateway_tasks(id)',
            'ALTER TABLE gateway_response_jobs ADD COLUMN epoch_id TEXT REFERENCES gateway_task_epochs(id)',
            'ALTER TABLE gateway_response_jobs ADD COLUMN durable_turn_id TEXT REFERENCES gateway_task_turns(id)',
            'ALTER TABLE gateway_response_jobs ADD COLUMN surface_id TEXT REFERENCES gateway_browser_surfaces(id)',
            'ALTER TABLE gateway_response_jobs ADD COLUMN generation_id TEXT',
            'ALTER TABLE gateway_response_jobs ADD COLUMN round_no INTEGER NOT NULL DEFAULT 1',
            'ALTER TABLE gateway_response_jobs ADD COLUMN previous_response_id TEXT',
            'ALTER TABLE gateway_response_jobs ADD COLUMN submit_intent_at INTEGER',
            'ALTER TABLE gateway_response_jobs ADD COLUMN baseline_users_json TEXT',
            'ALTER TABLE gateway_response_jobs ADD COLUMN baseline_assistants_json TEXT',
            'CREATE INDEX gateway_response_jobs_task ON gateway_response_jobs(task_id,created_at)',
            'CREATE INDEX gateway_response_jobs_turn ON gateway_response_jobs(durable_turn_id,round_no)',
            'CREATE INDEX gateway_response_jobs_surface ON gateway_response_jobs(surface_id,status)',
        ];
    }

    /** @return array<int,string> Step 06 MCP capability broker, tool roundtrip and operator settings. */
    public static function migrationSix(): array
    {
        return [
            "CREATE TABLE gateway_settings (id INTEGER PRIMARY KEY CHECK(id=1), mcp_enabled INTEGER NOT NULL DEFAULT 1 CHECK(mcp_enabled IN (0,1)), connector_name TEXT NOT NULL, mcp_invoke_timeout_ms INTEGER NOT NULL, wait_poll_interval_ms INTEGER NOT NULL, max_tool_calls_per_turn INTEGER NOT NULL, tool_result_max_bytes INTEGER NOT NULL, tunnel_id TEXT NOT NULL DEFAULT '', protected_runtime_key TEXT, updated_at INTEGER NOT NULL DEFAULT 0)",
            "INSERT INTO gateway_settings(id,mcp_enabled,connector_name,mcp_invoke_timeout_ms,wait_poll_interval_ms,max_tool_calls_per_turn,tool_result_max_bytes,tunnel_id,updated_at) VALUES (1,1,'RCMAKER Codex',90000,30000,64,2097152,'',0)",
            "CREATE TABLE gateway_mcp_bindings (id TEXT PRIMARY KEY, token_hash TEXT NOT NULL UNIQUE, task_id TEXT NOT NULL REFERENCES gateway_tasks(id) ON DELETE CASCADE, turn_id TEXT NOT NULL REFERENCES gateway_task_turns(id) ON DELETE CASCADE, current_response_id TEXT, tool_environment_hash TEXT NOT NULL, tool_environment_json TEXT NOT NULL, connector_name TEXT NOT NULL, status TEXT NOT NULL CHECK(status IN ('awaiting_start','running','completed','revoked')), sent_at INTEGER, started_at INTEGER, activity_revision INTEGER NOT NULL DEFAULT 0, completion_revision INTEGER, completion_hash TEXT, final_answer TEXT, expires_at INTEGER NOT NULL, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL, revoked_at INTEGER, UNIQUE(turn_id))",
            'CREATE INDEX gateway_mcp_bindings_status ON gateway_mcp_bindings(status,updated_at)',
            "CREATE TABLE gateway_mcp_activities (id TEXT PRIMARY KEY, binding_id TEXT NOT NULL REFERENCES gateway_mcp_bindings(id) ON DELETE CASCADE, status TEXT NOT NULL CHECK(status IN ('active','completed')), created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL)",
            'CREATE INDEX gateway_mcp_activities_binding ON gateway_mcp_activities(binding_id,status)',
            'ALTER TABLE gateway_tool_invocations ADD COLUMN binding_id TEXT REFERENCES gateway_mcp_bindings(id)',
            "ALTER TABLE gateway_tool_invocations ADD COLUMN wire_name TEXT NOT NULL DEFAULT ''",
            "ALTER TABLE gateway_tool_invocations ADD COLUMN arguments_json TEXT NOT NULL DEFAULT '{}'",
            'ALTER TABLE gateway_tool_invocations ADD COLUMN input_text TEXT',
            'ALTER TABLE gateway_tool_invocations ADD COLUMN result_hash TEXT',
            'ALTER TABLE gateway_tool_invocations ADD COLUMN delivery_count INTEGER NOT NULL DEFAULT 0',
            'ALTER TABLE gateway_tool_invocations ADD COLUMN delivered_at INTEGER',
            'ALTER TABLE gateway_tool_invocations ADD COLUMN completed_at INTEGER',
            'CREATE INDEX gateway_tool_invocations_binding ON gateway_tool_invocations(binding_id,status,created_at)',
            "ALTER TABLE gateway_response_jobs ADD COLUMN round_kind TEXT NOT NULL DEFAULT 'text'",
            'ALTER TABLE gateway_response_jobs ADD COLUMN mcp_binding_id TEXT REFERENCES gateway_mcp_bindings(id)',
            'ALTER TABLE gateway_response_jobs ADD COLUMN tool_environment_hash TEXT',
            'ALTER TABLE gateway_response_jobs ADD COLUMN tool_environment_json TEXT',
            "ALTER TABLE gateway_response_jobs ADD COLUMN browser_prefix TEXT NOT NULL DEFAULT ''",
            'CREATE INDEX gateway_response_jobs_mcp ON gateway_response_jobs(mcp_binding_id,round_no)',
        ];
    }

    /** @return array<int,string> Step 06 reliability receipts and project-private tunnel runtime state. */
    public static function migrationSeven(): array
    {
        return [
            'ALTER TABLE gateway_tool_invocations ADD COLUMN result_delivered_at INTEGER',
            "CREATE TABLE gateway_tunnel_state (id INTEGER PRIMARY KEY CHECK(id=1), status TEXT NOT NULL CHECK(status IN ('stopped','starting','ready','error')), network_revision INTEGER, binary_version TEXT, last_error TEXT, updated_at INTEGER NOT NULL DEFAULT 0)",
            "INSERT INTO gateway_tunnel_state(id,status,network_revision,binary_version,last_error,updated_at) VALUES (1,'stopped',NULL,NULL,NULL,0)",
        ];
    }

    /** @return array<int,string> Step 06 durable idempotency key for host tool operations. */
    public static function migrationEight(): array
    {
        return [
            'ALTER TABLE gateway_tool_invocations ADD COLUMN call_key TEXT',
            'CREATE UNIQUE INDEX gateway_tool_invocations_call_key ON gateway_tool_invocations(binding_id,call_key) WHERE call_key IS NOT NULL',
        ];
    }

    /** @return array<int,string> Step 06 operational defaults, appended after persisted v7/v8. */
    public static function migrationNine(): array
    {
        return [
            'UPDATE gateway_settings SET wait_poll_interval_ms=250 WHERE wait_poll_interval_ms=30000',
        ];
    }

    /** @return array<int,string> Step 06 ChatGPT connector verification state. */
    public static function migrationTen(): array
    {
        return [
            "CREATE TABLE gateway_connector_state (id INTEGER PRIMARY KEY CHECK(id=1), connector_name TEXT NOT NULL DEFAULT '', status TEXT NOT NULL CHECK(status IN ('unverified','verified','error')), verified_at INTEGER, last_error TEXT, updated_at INTEGER NOT NULL DEFAULT 0)",
            "INSERT INTO gateway_connector_state(id,connector_name,status,verified_at,last_error,updated_at) VALUES (1,'','unverified',NULL,NULL,0)",
        ];
    }

    /** @return array<int,string> Step 06 Responses tool_choice completion fence. */
    public static function migrationEleven(): array
    {
        return [
            'ALTER TABLE gateway_mcp_bindings ADD COLUMN requires_tool_call INTEGER NOT NULL DEFAULT 0 CHECK(requires_tool_call IN (0,1))',
            "CREATE TRIGGER gateway_mcp_required_tool_before_completion BEFORE UPDATE OF status ON gateway_mcp_bindings WHEN NEW.status='completed' AND NEW.requires_tool_call=1 AND NOT EXISTS (SELECT 1 FROM gateway_tool_invocations WHERE binding_id=OLD.id) BEGIN SELECT RAISE(ABORT, 'gateway_mcp_required_tool_missing'); END",
        ];
    }

    /**
     * Step 06 Connector ABI v2. ChatGPT caches a Connector's public MCP contract by identity,
     * so a changed tools/list contract must use a new default Connector name instead of silently
     * reusing the previously verified identity.
     * @return array<int,string>
     */
    public static function migrationTwelve(): array
    {
        return [
            "UPDATE gateway_settings SET connector_name='RCMAKER Codex2', updated_at=CASE WHEN updated_at<1 THEN 1 ELSE updated_at+1 END WHERE connector_name='RCMAKER Codex'",
            "UPDATE gateway_connector_state SET connector_name=(SELECT connector_name FROM gateway_settings WHERE id=1), status='unverified', verified_at=NULL, last_error=NULL, updated_at=(SELECT updated_at FROM gateway_settings WHERE id=1) WHERE id=1 AND (connector_name='' OR connector_name='RCMAKER Codex')",
        ];
    }

    /** @return array<int,string> Step 06 Connector verification must prove a real Tunnel -> local MCP roundtrip. */
    public static function migrationThirteen(): array
    {
        return [
            'ALTER TABLE gateway_connector_state ADD COLUMN probe_token_hash TEXT',
            'ALTER TABLE gateway_connector_state ADD COLUMN probe_expires_at INTEGER',
            'ALTER TABLE gateway_connector_state ADD COLUMN probe_started_at INTEGER',
            "UPDATE gateway_connector_state SET status='unverified', verified_at=NULL, last_error=NULL, probe_token_hash=NULL, probe_expires_at=NULL, probe_started_at=NULL, updated_at=CASE WHEN updated_at<1 THEN 1 ELSE updated_at+1 END",
        ];
    }

    /**
     * Step 06 ChatGPT one-shot tool confirmations plus a stronger Connector permission proof.
     * Existing installs opt into unattended one-shot confirmation because this product's standard
     * task mode is automatic. Runtime capability is proven separately by the command-class probe.
     * @return array<int,string>
     */
    public static function migrationFourteen(): array
    {
        return [
            'ALTER TABLE gateway_settings ADD COLUMN auto_approve_tool_calls INTEGER NOT NULL DEFAULT 1 CHECK(auto_approve_tool_calls IN (0,1))',
            "UPDATE gateway_connector_state SET status='unverified', verified_at=NULL, last_error=NULL, probe_token_hash=NULL, probe_expires_at=NULL, probe_started_at=NULL, updated_at=CASE WHEN updated_at<1 THEN 1 ELSE updated_at+1 END",
        ];
    }

    public static function migrationFifteen(): array
    {
        return [
            "UPDATE gateway_settings SET connector_name='RCMAKER Codex3', updated_at=CASE WHEN updated_at<1 THEN 1 ELSE updated_at+1 END WHERE connector_name='RCMAKER Codex2'",
            "UPDATE gateway_connector_state SET connector_name=(SELECT connector_name FROM gateway_settings WHERE id=1), status='unverified', verified_at=NULL, last_error=NULL, probe_token_hash=NULL, probe_expires_at=NULL, probe_started_at=NULL, updated_at=(SELECT updated_at FROM gateway_settings WHERE id=1) WHERE id=1",
        ];
    }

    /** Force a fresh ChatGPT Connector identity after the codex_exec approval ABI changed. */
    public static function migrationSixteen(): array
    {
        return [
            "UPDATE gateway_settings SET connector_name='RCMAKER Codex4', updated_at=CASE WHEN updated_at<1 THEN 1 ELSE updated_at+1 END WHERE connector_name='RCMAKER Codex3'",
            "UPDATE gateway_connector_state SET connector_name=(SELECT connector_name FROM gateway_settings WHERE id=1), status='unverified', verified_at=NULL, last_error=NULL, probe_token_hash=NULL, probe_expires_at=NULL, probe_started_at=NULL, updated_at=(SELECT updated_at FROM gateway_settings WHERE id=1) WHERE id=1",
        ];
    }

    /** Invalidate the old read/generic-action proof; readiness now requires the command-class codex_exec action. */
    public static function migrationSeventeen(): array
    {
        return [
            "UPDATE gateway_connector_state SET status='unverified', verified_at=NULL, last_error=NULL, probe_token_hash=NULL, probe_expires_at=NULL, probe_started_at=NULL, updated_at=CASE WHEN updated_at<1 THEN 1 ELSE updated_at+1 END",
        ];
    }

    /** Step 06 browser multipart transport receipts; these prevent replay after a worker restart. */
    public static function migrationEighteen(): array
    {
        return [
            'ALTER TABLE gateway_response_jobs ADD COLUMN transport_transaction_id TEXT',
            'ALTER TABLE gateway_response_jobs ADD COLUMN transport_parts_total INTEGER NOT NULL DEFAULT 0',
            'ALTER TABLE gateway_response_jobs ADD COLUMN transport_stage_sent INTEGER NOT NULL DEFAULT 0',
            'ALTER TABLE gateway_response_jobs ADD COLUMN transport_stage_acked INTEGER NOT NULL DEFAULT 0',
            'ALTER TABLE gateway_response_jobs ADD COLUMN transport_stage_effort TEXT',
            'ALTER TABLE gateway_response_jobs ADD COLUMN transport_final_effort TEXT',
        ];
    }

    /** Step 06 Responses tool_choice=required is enforced per round, not only once per durable turn. */
    public static function migrationNineteen(): array
    {
        return [
            'ALTER TABLE gateway_mcp_bindings ADD COLUMN required_tool_count INTEGER NOT NULL DEFAULT 0 CHECK(required_tool_count >= 0)',
            'UPDATE gateway_mcp_bindings SET required_tool_count=1 WHERE requires_tool_call=1 AND required_tool_count=0',
            "CREATE TRIGGER gateway_mcp_required_tool_count_before_completion BEFORE UPDATE OF status ON gateway_mcp_bindings WHEN NEW.status='completed' AND (SELECT count(*) FROM gateway_tool_invocations WHERE binding_id=OLD.id) < NEW.required_tool_count BEGIN SELECT RAISE(ABORT, 'gateway_mcp_required_tool_count_missing'); END",
        ];
    }

    /** Step 07 account catalog, client integration journal and durable context handoffs. */
    public static function migrationTwenty(): array
    {
        return [
            "CREATE TABLE gateway_model_catalog (id INTEGER PRIMARY KEY CHECK(id=1), capabilities_json TEXT, observed_at INTEGER NOT NULL DEFAULT 0, browser_generation INTEGER NOT NULL DEFAULT 0, native_catalog_json TEXT, native_verified_at INTEGER NOT NULL DEFAULT 0)",
            'INSERT INTO gateway_model_catalog(id) VALUES(1)',
            "CREATE TABLE gateway_codex_integrations (id TEXT PRIMARY KEY, config_path TEXT NOT NULL UNIQUE, before_text TEXT NOT NULL, after_text TEXT NOT NULL, before_hash TEXT NOT NULL, after_hash TEXT NOT NULL, state TEXT NOT NULL, installed_at INTEGER NOT NULL, catalog_seen_at INTEGER, response_seen_at INTEGER, last_error TEXT)",
            'ALTER TABLE gateway_settings ADD COLUMN context_max_parts INTEGER NOT NULL DEFAULT 3',
            "ALTER TABLE gateway_settings ADD COLUMN context_transport TEXT NOT NULL DEFAULT 'auto'",
            'ALTER TABLE gateway_settings ADD COLUMN context_extended INTEGER NOT NULL DEFAULT 0',
            'ALTER TABLE gateway_settings ADD COLUMN response_timeout_seconds INTEGER NOT NULL DEFAULT 600',
            'ALTER TABLE gateway_settings ADD COLUMN context_stage_timeout_seconds INTEGER NOT NULL DEFAULT 120',
            'ALTER TABLE gateway_settings ADD COLUMN compaction_timeout_seconds INTEGER NOT NULL DEFAULT 180',
            'ALTER TABLE gateway_response_jobs ADD COLUMN context_json TEXT',
            'ALTER TABLE gateway_response_jobs ADD COLUMN request_identity TEXT',
            'ALTER TABLE gateway_response_jobs ADD COLUMN budget_json TEXT',
            "ALTER TABLE gateway_response_jobs ADD COLUMN operation_kind TEXT NOT NULL DEFAULT 'response'",
            'ALTER TABLE gateway_response_jobs ADD COLUMN compact_id TEXT',
            'ALTER TABLE gateway_response_jobs ADD COLUMN input_tokens INTEGER NOT NULL DEFAULT 0',
            'CREATE UNIQUE INDEX gateway_response_request_identity ON gateway_response_jobs(request_identity) WHERE request_identity IS NOT NULL',
            "CREATE TABLE gateway_context_checkpoints (id TEXT PRIMARY KEY, task_id TEXT NOT NULL, source_epoch_id TEXT NOT NULL, source_response_id TEXT, request_hash TEXT NOT NULL, response_id TEXT, protocol TEXT NOT NULL, state TEXT NOT NULL, summary TEXT, response_json TEXT, created_at INTEGER NOT NULL, committed_at INTEGER, consumed_at INTEGER, UNIQUE(task_id,source_epoch_id))",
            "CREATE TABLE gateway_model_cooldowns (scope TEXT PRIMARY KEY, until_at INTEGER NOT NULL, error_code TEXT NOT NULL, updated_at INTEGER NOT NULL)",
        ];
    }

    /** Added after v20 was applied by the development monitor; never rewrite that migration. */
    public static function migrationTwentyOne(): array
    {
        return ['ALTER TABLE gateway_response_jobs ADD COLUMN integration_id TEXT'];
    }

    /** @return array<int,string> Bounded unsent repair and exact-parent rolling checkpoints. */
    public static function migrationTwentyTwo(): array
    {
        return [
            'ALTER TABLE gateway_response_jobs ADD COLUMN repair_count INTEGER NOT NULL DEFAULT 0',
            'ALTER TABLE gateway_response_jobs ADD COLUMN checkpoint_marker TEXT',
            'CREATE TABLE gateway_rolling_checkpoints (response_id TEXT PRIMARY KEY REFERENCES gateway_response_jobs(id), task_id TEXT NOT NULL, turn_id TEXT, context_hash TEXT NOT NULL, answer_hash TEXT NOT NULL, summary TEXT NOT NULL, created_at INTEGER NOT NULL)',
            'CREATE INDEX gateway_rolling_checkpoints_task ON gateway_rolling_checkpoints(task_id,created_at)',
        ];
    }

    /** @return array<int,array<int,string>> Ordered immutable migrations. */
    private static function migrations(): array
    {
        return [
            1 => self::migrationOne(),
            2 => self::migrationTwo(),
            3 => self::migrationThree(),
            4 => self::migrationFour(),
            5 => self::migrationFive(),
            6 => self::migrationSix(),
            7 => self::migrationSeven(),
            8 => self::migrationEight(),
            9 => self::migrationNine(),
            10 => self::migrationTen(),
            11 => self::migrationEleven(),
            12 => self::migrationTwelve(),
            13 => self::migrationThirteen(),
            14 => self::migrationFourteen(),
            15 => self::migrationFifteen(),
            16 => self::migrationSixteen(),
            17 => self::migrationSeventeen(),
            18 => self::migrationEighteen(),
            19 => self::migrationNineteen(),
            20 => self::migrationTwenty(),
            21 => self::migrationTwentyOne(),
            22 => self::migrationTwentyTwo(),
            23 => ['ALTER TABLE gateway_tunnel_state ADD COLUMN desired_running INTEGER NOT NULL DEFAULT 1'],
            24 => [
                'ALTER TABLE gateway_response_jobs ADD COLUMN transport_context_json TEXT',
                'ALTER TABLE gateway_response_jobs ADD COLUMN rolling_source_response_id TEXT REFERENCES gateway_response_jobs(id)',
            ],
            25 => [
                "ALTER TABLE gateway_connector_state ADD COLUMN runtime_generation TEXT NOT NULL DEFAULT ''",
                'ALTER TABLE gateway_connector_state ADD COLUMN probe_generation TEXT',
                'ALTER TABLE gateway_connector_state ADD COLUMN verified_generation TEXT',
                'ALTER TABLE gateway_connector_state ADD COLUMN recheck_required INTEGER NOT NULL DEFAULT 0',
                'ALTER TABLE gateway_connector_state ADD COLUMN recheck_command_id INTEGER',
            ],
            26 => [
                "UPDATE gateway_settings SET context_stage_timeout_seconds=180, updated_at=CASE WHEN updated_at<1 THEN 1 ELSE updated_at+1 END WHERE context_stage_timeout_seconds=120",
            ],
            27 => [
                "ALTER TABLE gateway_settings ADD COLUMN skill_transport TEXT NOT NULL DEFAULT 'inline' CHECK(skill_transport IN ('inline','file'))",
                "CREATE TABLE gateway_attachments (id TEXT PRIMARY KEY, kind TEXT NOT NULL CHECK(kind IN ('image','skill','file','artifact')), filename TEXT NOT NULL, mime_type TEXT NOT NULL, byte_size INTEGER NOT NULL CHECK(byte_size >= 0), sha256 TEXT NOT NULL, relative_path TEXT NOT NULL UNIQUE, metadata_json TEXT NOT NULL DEFAULT '{}', status TEXT NOT NULL DEFAULT 'ready' CHECK(status IN ('ready','missing','corrupt','deleted')), created_at INTEGER NOT NULL, verified_at INTEGER)",
                'CREATE UNIQUE INDEX gateway_attachments_identity ON gateway_attachments(kind,mime_type,sha256)',
                "CREATE TABLE gateway_response_attachments (response_id TEXT NOT NULL REFERENCES gateway_response_jobs(id) ON DELETE CASCADE, attachment_id TEXT NOT NULL REFERENCES gateway_attachments(id), attachment_ref TEXT NOT NULL, ordinal INTEGER NOT NULL CHECK(ordinal >= 0), transport_mode TEXT NOT NULL CHECK(transport_mode IN ('upload','inline')), created_at INTEGER NOT NULL, PRIMARY KEY(response_id,attachment_ref))",
                'CREATE INDEX gateway_response_attachments_order ON gateway_response_attachments(response_id,ordinal)',
            ],
            28 => [
                "CREATE TABLE gateway_artifacts (id TEXT PRIMARY KEY, response_id TEXT NOT NULL REFERENCES gateway_response_jobs(id), task_id TEXT NOT NULL REFERENCES gateway_tasks(id), filename TEXT NOT NULL, mime_type TEXT NOT NULL, byte_size INTEGER NOT NULL CHECK(byte_size > 0), sha256 TEXT NOT NULL, relative_path TEXT NOT NULL UNIQUE, source_url TEXT, source_kind TEXT NOT NULL DEFAULT 'browser_download', status TEXT NOT NULL DEFAULT 'ready' CHECK(status IN ('ready','missing','corrupt','deleted')), created_at INTEGER NOT NULL, verified_at INTEGER NOT NULL)",
                'CREATE UNIQUE INDEX gateway_artifacts_response_identity ON gateway_artifacts(response_id,sha256,filename)',
                'CREATE INDEX gateway_artifacts_task ON gateway_artifacts(task_id,created_at)',
            ],
            29 => [
                "CREATE TABLE gateway_adapter_capabilities (adapter_id TEXT NOT NULL, adapter_version TEXT NOT NULL, capability TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'unknown' CHECK(status IN ('verified','unavailable','unknown','stale')), evidence_json TEXT NOT NULL DEFAULT '{}', observed_at INTEGER NOT NULL DEFAULT 0, PRIMARY KEY(adapter_id,adapter_version,capability))",
                'CREATE INDEX gateway_adapter_capabilities_status ON gateway_adapter_capabilities(adapter_id,adapter_version,status,capability)',
            ],
            30 => [
                'ALTER TABLE gateway_response_jobs ADD COLUMN submitted_document_nonce TEXT',
                'ALTER TABLE gateway_response_jobs ADD COLUMN continuation_source_response_id TEXT REFERENCES gateway_response_jobs(id)',
            ],
            31 => [
                'CREATE TABLE gateway_portable_checkpoints (id TEXT PRIMARY KEY, source_model TEXT NOT NULL, summary TEXT NOT NULL, created_at INTEGER NOT NULL)',
            ],
            32 => [
                'CREATE TABLE gateway_task_conversations (base_task_id TEXT PRIMARY KEY REFERENCES gateway_tasks(id) ON DELETE CASCADE, current_task_id TEXT NOT NULL REFERENCES gateway_tasks(id), current_task_key TEXT NOT NULL, current_owner_key TEXT NOT NULL, generation INTEGER NOT NULL CHECK(generation > 0), updated_at INTEGER NOT NULL)',
            ],
            33 => [
                'ALTER TABLE gateway_settings ADD COLUMN official_handoff_summary INTEGER NOT NULL DEFAULT 0 CHECK(official_handoff_summary IN (0,1))',
            ],
            34 => [
                "UPDATE gateway_connector_state SET status='unverified', recheck_required=1, recheck_command_id=NULL, last_error=NULL, updated_at=updated_at+1 WHERE status='error' AND recheck_command_id IN (SELECT id FROM gateway_browser_commands WHERE status='failed' AND result_json LIKE '%\"code\":\"BROWSER_SURFACE_STALE\"%')",
            ],
            35 => [
                "UPDATE gateway_connector_state SET status='unverified', recheck_required=1, recheck_command_id=NULL, last_error=NULL, updated_at=updated_at+1 WHERE status='error' AND recheck_command_id IN (SELECT id FROM gateway_browser_commands WHERE status='failed' AND result_json LIKE '%\"code\":\"CHATGPT_CONNECTOR_SELECTION_FAILED\"%')",
            ],
            36 => [
                'ALTER TABLE gateway_mcp_bindings ADD COLUMN parallel_tool_calls INTEGER NOT NULL DEFAULT 0 CHECK(parallel_tool_calls IN (0,1))',
            ],
            37 => [
                // The original ten-minute default cuts off an active Web reasoning step.
                // Preserve explicit non-default choices while upgrading untouched installs.
                'UPDATE gateway_settings SET response_timeout_seconds=14400, updated_at=updated_at+1 WHERE response_timeout_seconds=600',
            ],
            38 => [
                "ALTER TABLE gateway_settings ADD COLUMN web_initial_contract_template TEXT NOT NULL DEFAULT ''",
                "ALTER TABLE gateway_settings ADD COLUMN web_resume_contract_template TEXT NOT NULL DEFAULT ''",
            ],
            39 => [
                "ALTER TABLE gateway_settings ADD COLUMN web_readonly_contract_template TEXT NOT NULL DEFAULT ''",
                "ALTER TABLE gateway_settings ADD COLUMN web_compact_contract_template TEXT NOT NULL DEFAULT ''",
            ],
            40 => [
                "CREATE TABLE gateway_web_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, task_id TEXT NOT NULL REFERENCES gateway_tasks(id) ON DELETE CASCADE, turn_id TEXT NOT NULL REFERENCES gateway_task_turns(id) ON DELETE CASCADE, response_id TEXT NOT NULL REFERENCES gateway_response_jobs(id) ON DELETE CASCADE, direction TEXT NOT NULL CHECK(direction IN ('outbound','inbound')), kind TEXT NOT NULL, stage_no INTEGER NOT NULL DEFAULT 0, status TEXT NOT NULL CHECK(status IN ('intent','accepted','observed')), text TEXT NOT NULL, sha256 TEXT NOT NULL, byte_count INTEGER NOT NULL, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL, UNIQUE(response_id,direction,kind,stage_no,sha256))",
                'CREATE INDEX gateway_web_messages_task_order ON gateway_web_messages(task_id,id)',
            ],
            41 => [
                // The original 64-call default prevents already observed long-running
                // Web turns. Zero means no count cap; the durable turn deadline still applies.
                'UPDATE gateway_settings SET max_tool_calls_per_turn=0, updated_at=updated_at+1 WHERE max_tool_calls_per_turn=64',
            ],
            42 => [
                // ChatGPT caches each App's public MCP tools. Codex4 still exposes the retired
                // work-update tool to Web even after the server switched to the six-tool ABI.
                "UPDATE gateway_settings SET connector_name='RCMAKER Codex5', updated_at=CASE WHEN updated_at<1 THEN 1 ELSE updated_at+1 END WHERE connector_name='RCMAKER Codex4'",
                "UPDATE gateway_connector_state SET connector_name='RCMAKER Codex5', status='unverified', verified_at=NULL, verified_generation=NULL, runtime_generation=lower(hex(randomblob(16))), probe_token_hash=NULL, probe_expires_at=NULL, probe_started_at=NULL, probe_generation=NULL, recheck_required=0, recheck_command_id=NULL, last_error=NULL, updated_at=(SELECT updated_at FROM gateway_settings WHERE id=1) WHERE connector_name='RCMAKER Codex4' AND (SELECT connector_name FROM gateway_settings WHERE id=1)='RCMAKER Codex5'",
            ],
            43 => [
                "CREATE TABLE gateway_compaction_handoffs (compact_response_id TEXT PRIMARY KEY REFERENCES gateway_response_jobs(id) ON DELETE CASCADE, checkpoint_id TEXT NOT NULL REFERENCES gateway_context_checkpoints(id) ON DELETE CASCADE, task_id TEXT NOT NULL REFERENCES gateway_tasks(id) ON DELETE CASCADE, source_response_id TEXT NOT NULL REFERENCES gateway_response_jobs(id), source_answer_hash TEXT NOT NULL, handoff_id TEXT NOT NULL UNIQUE, token_hash TEXT NOT NULL UNIQUE, state TEXT NOT NULL CHECK(state IN ('prepared','submit_intent','accepted','consumed')), summary TEXT, created_at INTEGER NOT NULL, expires_at INTEGER NOT NULL, submit_intent_at INTEGER, accepted_at INTEGER, consumed_at INTEGER)",
            ],
            44 => [
                'ALTER TABLE gateway_tool_invocations ADD COLUMN result_delivered_at_us INTEGER',
            ],
            45 => [
                'ALTER TABLE gateway_browser_state ADD COLUMN profile_reset_required INTEGER NOT NULL DEFAULT 0 CHECK(profile_reset_required IN (0,1))',
                "UPDATE gateway_browser_state SET profile_reset_required=1, auto_start=0, updated_at=updated_at+1 WHERE id=1 AND (SELECT status FROM gateway_browser_commands WHERE action='reset_profile' ORDER BY id DESC LIMIT 1) IN ('queued','running','failed')",
            ],
            46 => [
                // Web summaries can take longer than the old three-minute default.
                // Match the ordinary four-hour wait, preserving other saved choices.
                'UPDATE gateway_settings SET compaction_timeout_seconds=14400, updated_at=updated_at+1 WHERE compaction_timeout_seconds=180',
            ],
            47 => [
                "ALTER TABLE gateway_settings ADD COLUMN ui_locale TEXT NOT NULL DEFAULT '' CHECK(ui_locale IN ('','en','zh-CN'))",
            ],
            48 => [
                "CREATE TABLE gateway_usage_account (id INTEGER PRIMARY KEY CHECK(id=1), account_key TEXT, plan_type TEXT NOT NULL DEFAULT 'unknown', observed_at INTEGER NOT NULL DEFAULT 0, profile_identity TEXT NOT NULL DEFAULT '')",
                'INSERT INTO gateway_usage_account(id) VALUES (1)',
                'ALTER TABLE gateway_web_messages ADD COLUMN account_key TEXT',
                'CREATE INDEX gateway_web_messages_usage ON gateway_web_messages(account_key,direction,status,created_at)',
                'ALTER TABLE gateway_settings ADD COLUMN pro_weekly_budget INTEGER NOT NULL DEFAULT 0 CHECK(pro_weekly_budget>=0)',
            ],
            49 => [
                'ALTER TABLE gateway_response_jobs ADD COLUMN physical_selection_json TEXT',
                'ALTER TABLE gateway_web_messages ADD COLUMN selected_model TEXT',
                'ALTER TABLE gateway_web_messages ADD COLUMN selected_effort TEXT',
                'ALTER TABLE gateway_web_messages ADD COLUMN purpose TEXT',
                "CREATE TABLE gateway_probe_messages (token_hash TEXT PRIMARY KEY, account_key TEXT, selected_model TEXT NOT NULL, selected_effort TEXT NOT NULL, status TEXT NOT NULL CHECK(status IN ('intent','accepted')), created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL)",
                'CREATE INDEX gateway_probe_messages_usage ON gateway_probe_messages(account_key,status,created_at)',
            ],
            50 => [
                'ALTER TABLE gateway_response_jobs ADD COLUMN transport_budget_json TEXT',
            ],
            51 => [
                'ALTER TABLE gateway_response_jobs ADD COLUMN transport_decision_json TEXT',
                "CREATE TABLE gateway_task_cleanup (id INTEGER PRIMARY KEY AUTOINCREMENT, scope TEXT NOT NULL, status TEXT NOT NULL, requested_at INTEGER NOT NULL, updated_at INTEGER NOT NULL, deleted_count INTEGER NOT NULL DEFAULT 0, skipped_count INTEGER NOT NULL DEFAULT 0, bytes_before INTEGER NOT NULL DEFAULT 0, bytes_after INTEGER NOT NULL DEFAULT 0, vacuum_done INTEGER NOT NULL DEFAULT 0, error_code TEXT)",
                'CREATE TABLE gateway_task_cleanup_items (cleanup_id INTEGER NOT NULL REFERENCES gateway_task_cleanup(id) ON DELETE CASCADE, task_id TEXT NOT NULL, status TEXT NOT NULL, PRIMARY KEY(cleanup_id,task_id))',
                'CREATE INDEX gateway_task_cleanup_pending ON gateway_task_cleanup(status,id)',
                'CREATE INDEX gateway_task_cleanup_items_pending ON gateway_task_cleanup_items(cleanup_id,status)',
                "CREATE TABLE gateway_task_cleanup_files (cleanup_id INTEGER NOT NULL REFERENCES gateway_task_cleanup(id) ON DELETE CASCADE, kind TEXT NOT NULL, resource_id TEXT NOT NULL, relative_path TEXT NOT NULL, available_at INTEGER NOT NULL DEFAULT 0, PRIMARY KEY(cleanup_id,kind,resource_id))",
                "CREATE TABLE gateway_usage_archive (account_scope TEXT NOT NULL, accepted_at INTEGER NOT NULL, model TEXT NOT NULL, purpose TEXT NOT NULL, submissions INTEGER NOT NULL, pro_submissions INTEGER NOT NULL, verified_submissions INTEGER NOT NULL, stages INTEGER NOT NULL, input_tokens INTEGER NOT NULL, output_tokens INTEGER NOT NULL, PRIMARY KEY(account_scope,accepted_at,model,purpose))",
                // A conversation key is an enduring routing identity, not a retained transcript.
                // Removing an old base task must not cascade away a newer task's routing entry.
                'CREATE TABLE gateway_task_conversations_retained (base_task_id TEXT PRIMARY KEY, current_task_id TEXT NOT NULL REFERENCES gateway_tasks(id), current_task_key TEXT NOT NULL, current_owner_key TEXT NOT NULL, generation INTEGER NOT NULL CHECK(generation > 0), updated_at INTEGER NOT NULL)',
                'INSERT INTO gateway_task_conversations_retained SELECT * FROM gateway_task_conversations',
                'DROP TABLE gateway_task_conversations',
                'ALTER TABLE gateway_task_conversations_retained RENAME TO gateway_task_conversations',
            ],
            52 => [
                'CREATE TABLE gateway_checkpoint_proofs (id TEXT PRIMARY KEY, envelope_hash TEXT NOT NULL, retained_refs_json TEXT NOT NULL, created_at INTEGER NOT NULL)',
            ],
            53 => [
                // Parent deletion and historical pointer clearing otherwise scan large payload tables
                // for each response. Index the child references without changing cascade semantics.
                'CREATE INDEX gateway_response_jobs_epoch ON gateway_response_jobs(epoch_id)',
                'CREATE INDEX gateway_response_jobs_command ON gateway_response_jobs(command_id)',
                'CREATE INDEX gateway_response_jobs_rolling_source ON gateway_response_jobs(rolling_source_response_id)',
                'CREATE INDEX gateway_response_jobs_continuation_source ON gateway_response_jobs(continuation_source_response_id)',
                'CREATE INDEX gateway_tool_invocations_response ON gateway_tool_invocations(response_id)',
                'CREATE INDEX gateway_tool_invocations_turn ON gateway_tool_invocations(turn_id)',
                'CREATE INDEX gateway_task_turns_epoch ON gateway_task_turns(epoch_id)',
                'CREATE INDEX gateway_task_turns_surface ON gateway_task_turns(surface_id)',
                'CREATE INDEX gateway_mcp_bindings_task ON gateway_mcp_bindings(task_id)',
                'CREATE INDEX gateway_web_messages_turn ON gateway_web_messages(turn_id)',
                'CREATE INDEX gateway_compaction_handoffs_source ON gateway_compaction_handoffs(source_response_id)',
                'CREATE INDEX gateway_compaction_handoffs_checkpoint ON gateway_compaction_handoffs(checkpoint_id)',
                'CREATE INDEX gateway_compaction_handoffs_task ON gateway_compaction_handoffs(task_id)',
                'CREATE INDEX gateway_task_conversations_current ON gateway_task_conversations(current_task_id)',
                'CREATE INDEX gateway_response_attachments_attachment ON gateway_response_attachments(attachment_id)',
            ],
            54 => [
                "CREATE TABLE gateway_login_lifecycle (id INTEGER PRIMARY KEY CHECK(id=1), codex_identity TEXT, profile_codex_identity TEXT, login_account_key TEXT, login_started_at INTEGER NOT NULL DEFAULT 0, snoozed_reminder_id TEXT, snoozed_until INTEGER NOT NULL DEFAULT 0, updated_at INTEGER NOT NULL DEFAULT 0)",
                'INSERT INTO gateway_login_lifecycle(id) VALUES (1)',
            ],
            55 => [
                "ALTER TABLE gateway_network_revisions ADD COLUMN browser_timezone TEXT NOT NULL DEFAULT 'auto'",
            ],
            56 => [
                'ALTER TABLE gateway_settings ADD COLUMN steering_full_context INTEGER NOT NULL DEFAULT 0',
            ],
            57 => [
                "ALTER TABLE gateway_tunnel_state ADD COLUMN preparation_json TEXT NOT NULL DEFAULT '{}'",
            ],
            // Keep hot metadata lookups away from historical context/result overflow pages.
            // The implicit rowid suffix also preserves exact latest-round ordering without sorting.
            58 => [
                'CREATE INDEX gateway_response_jobs_task_kind ON gateway_response_jobs(task_id,round_kind)',
                'CREATE INDEX gateway_response_jobs_task_status ON gateway_response_jobs(task_id,status)',
                'CREATE INDEX gateway_response_jobs_compact_source ON gateway_response_jobs(operation_kind,previous_response_id)',
            ],
        ];
    }

    /**
     * Initialize idempotently under a bounded cross-process lock.
     * @param callable|null $progress Optional local startup observer, called only by the migration owner.
     * @throws GatewayError on storage or migration failure; observer errors never affect storage.
     */
    public static function initialize(?callable $progress = null): void
    {
        if (self::$ready) {
            return;
        }
        $path = Config::get('gateway', 'database_file');
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new GatewayError('STORAGE_UNAVAILABLE', '无法创建本地数据目录，请检查写入权限。', 503);
        }
        $lock = @fopen($path . '.init.lock', 'c');
        if ($lock === false) {
            throw new GatewayError('STORAGE_UNAVAILABLE', '无法打开数据初始化锁，请检查写入权限。', 503);
        }
        $locked = false;
        $upgradeStarted = null;
        $deadline = microtime(true) + 2;
        try {
            do {
                $locked = flock($lock, LOCK_EX | LOCK_NB);
                if (!$locked) {
                    usleep(20000);
                }
            } while (!$locked && microtime(true) < $deadline);
            if (!$locked) {
                throw new GatewayError('STORAGE_BUSY', '本地数据正在初始化，请稍后重试。', 503);
            }
            $db = DB('medoo', 'sqlite');
            // SQLite-specific PRAGMAs and DDL are intentionally below AutoForm/SDB.
            // Several independent task workers share this connection-managed WAL store.
            // Wait for short competing writes before entering a transaction; never replay
            // the transaction body or its potentially external effects after an error.
            $db->query('PRAGMA busy_timeout=' . self::WRITER_WAIT_MILLISECONDS);
            $db->query('PRAGMA foreign_keys=ON');
            $journalMode = strtolower((string) $db->query('PRAGMA journal_mode')->fetchColumn());
            if ($journalMode !== 'wal'
                && strtolower((string) $db->query('PRAGMA journal_mode=WAL')->fetchColumn()) !== 'wal') {
                throw new \RuntimeException('WAL unavailable');
            }
            $db->query('PRAGMA synchronous=FULL');
            $migrations = self::migrations();
            // Every MCP tool call has a fresh PHP child. An already-current schema needs
            // checksum/state validation, not another writer transaction or CREATE TABLE.
            // The initialization file lock still serializes migration checks/upgrades.
            $hasSchema = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='gateway_schema'")->fetchColumn() !== false;
            $currentVersion = $hasSchema ? self::validatedVersion($db, $migrations) : 0;
            if ($hasSchema && $currentVersion === count($migrations)) {
                self::validateInitializedState($db);
                self::$ready = true;
                return;
            }
            $indexes = 0;
            foreach ($migrations as $version => $statements) {
                if ($version <= $currentVersion) continue;
                foreach ($statements as $sql) {
                    if (preg_match('/\ACREATE (?:UNIQUE )?INDEX\b/i', $sql)) $indexes++;
                }
            }
            $upgradeStarted = hrtime(true);
            $notify = static function (string $phase, int $index = 0) use ($progress, $currentVersion, $migrations, $indexes, $upgradeStarted): void {
                if ($progress === null) return;
                try {
                    $progress(['phase' => $phase, 'from_version' => $currentVersion, 'to_version' => count($migrations),
                        'index' => $index, 'indexes' => $indexes, 'elapsed_seconds' => (hrtime(true) - $upgradeStarted) / 1000000000]);
                } catch (\Throwable) {
                    // A closed terminal or presentation error cannot roll back an upgrade.
                }
            };
            $notify('starting');
            self::transaction(function () use ($db, $migrations, $notify): void {
                $db->query('CREATE TABLE IF NOT EXISTS gateway_schema (version INTEGER PRIMARY KEY, checksum TEXT NOT NULL)');
                $current = self::validatedVersion($db, $migrations);
                $freshInstallation = $current === 0;
                $index = 0;
                foreach ($migrations as $version => $statements) {
                    if ($version <= $current) {
                        continue;
                    }
                    foreach ($statements as $sql) {
                        $buildingIndex = preg_match('/\ACREATE (?:UNIQUE )?INDEX\b/i', $sql) === 1;
                        if ($buildingIndex) $notify('index_starting', ++$index);
                        $db->query($sql);
                        if ($buildingIndex) $notify('index_finished', $index);
                    }
                    $hash = hash('sha256', json_encode($statements, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                    $db->query('INSERT INTO gateway_schema(version,checksum) VALUES (:version,:checksum)', [':version' => $version, ':checksum' => $hash]);
                    $db->query('PRAGMA user_version=' . (int) $version);
                    $current = $version;
                }
                if ($freshInstallation) {
                    // Seed only a brand-new store. Historical migrations remain immutable and
                    // upgrades never rename an established App or overwrite saved preferences.
                    SDB(null, 'medoo', 'sqlite')->table('gateway_settings')->where('id', 1)->update([
                        'connector_name' => (string) Config::get('gateway', 'default_connector_name'),
                        'context_transport' => 'auto',
                        'context_max_parts' => (int) Config::get('gateway', 'default_context_max_parts'),
                        'context_extended' => (int) Config::get('gateway', 'default_context_extended'),
                        'ui_locale' => (string) Config::get('gateway', 'default_locale'),
                    ]);
                    SDB(null, 'medoo', 'sqlite')->table('gateway_connector_state')->where('id', 1)->update([
                        'connector_name' => (string) Config::get('gateway', 'default_connector_name'),
                    ]);
                }
                self::validateInitializedState($db);
            });
            self::$ready = true;
            $notify('completed');
        } catch (GatewayError $error) {
            if ($upgradeStarted !== null) $notify('failed');
            throw $error;
        } catch (\Throwable $error) {
            if ($upgradeStarted !== null) $notify('failed');
            throw new GatewayError('STORAGE_UNAVAILABLE', '本地数据库不可用，请检查磁盘空间、写入权限或文件占用。', 503);
        } finally {
            if ($locked) {
                flock($lock, LOCK_UN);
            }
            fclose($lock);
        }
    }

    /** Validate all immutable migration checksums and the matching SQLite version marker without a write. */
    private static function validatedVersion(object $db, array $migrations): int
    {
        $versions = $db->query('SELECT version,checksum FROM gateway_schema ORDER BY version')->fetchAll(\PDO::FETCH_ASSOC);
        $current = 0;
        foreach ($versions as $index => $version) {
            $number = (int) $version['version'];
            if ($number !== $index + 1 || !isset($migrations[$number])) {
                throw new GatewayError('SCHEMA_MISMATCH', '数据库版本或迁移校验不匹配；已保留原数据，请使用兼容版本。', 503);
            }
            $hash = hash('sha256', json_encode($migrations[$number], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            if (!hash_equals($hash, (string) $version['checksum'])) {
                throw new GatewayError('SCHEMA_MISMATCH', '数据库版本或迁移校验不匹配；已保留原数据，请使用兼容版本。', 503);
            }
            $current = $number;
        }
        if ((int) $db->query('PRAGMA user_version')->fetchColumn() !== $current) {
            throw new GatewayError('SCHEMA_MISMATCH', '数据库版本标记不一致，已停止写入。', 503);
        }
        return $current;
    }

    /** Verify singleton seed records on both the current-schema read path and a newly applied migration. */
    private static function validateInitializedState(object $db): void
    {
        $state = $db->query('SELECT id,saved_revision FROM gateway_network_state WHERE id=1')->fetch(\PDO::FETCH_ASSOC);
        if (!$state || (int) $state['saved_revision'] < 1) {
            throw new GatewayError('SCHEMA_MISMATCH', '数据库初始化记录不完整，已停止写入。', 503);
        }
        $browser = $db->query('SELECT id,status FROM gateway_browser_state WHERE id=1')->fetch(\PDO::FETCH_ASSOC);
        if (!$browser || (int) $browser['id'] !== 1) {
            throw new GatewayError('SCHEMA_MISMATCH', '浏览器工作区初始化记录不完整，已停止写入。', 503);
        }
    }

    /**
     * Execute a writer transaction once after bounded, contention-only lock acquisition.
     * BEGIN IMMEDIATE avoids read-to-write upgrades; lock failure before BEGIN is typed
     * separately from errors after the callback starts, which are never retried here.
     * @param callable $operation No network/process calls inside.
     * @return mixed
     * @throws GatewayError STORAGE_BUSY only when no transaction body has run.
     * @throws \Throwable Original operation/commit failure; never replayed.
     */
    public static function transaction(callable $operation): mixed
    {
        $db = DB('medoo', 'sqlite');
        $begun = false;
        $started = hrtime(true);
        $acquired = null;
        $failure = null;
        try {
            $beginStatement = self::beginWriter($db);
            $begun = true;
            $acquired = hrtime(true);
            $beginStatement->closeCursor();
            unset($beginStatement);
            $result = $operation();
            $db->query('COMMIT');
            return $result;
        } catch (\Throwable $error) {
            $failure = $error instanceof GatewayError ? $error->errorCode : get_class($error);
            if ($begun) {
                $db->query('ROLLBACK');
            }
            throw $error;
        } finally {
            self::recordSlowTransaction($started, $acquired, $failure);
        }
    }

    /** Record only slow lock/transaction timings after cleanup; no SQL, values or exception text. */
    private static function recordSlowTransaction(int $started, ?int $acquired, ?string $failure): void
    {
        $finished = hrtime(true);
        if ($finished - $started < 1000000000) return;
        try {
            $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)[2] ?? [];
            $operation = (string)($caller['class'] ?? '') . '::' . (string)($caller['function'] ?? 'unknown');
            if (!preg_match('/\A[A-Za-z_\\\\][A-Za-z0-9_\\\\]*::[A-Za-z_][A-Za-z0-9_]*\z/D', $operation)) $operation = 'unknown';
            $line = '[storage-transaction] ' . json_encode(['event'=>'storage_transaction_slow',
                'at'=>time(), 'pid'=>getmypid(), 'operation'=>$operation,
                'wait_ms'=>round((($acquired ?? $finished) - $started) / 1000000, 2),
                'held_ms'=>$acquired === null ? 0 : round(($finished - $acquired) / 1000000, 2),
                'error_code'=>$failure], JSON_THROW_ON_ERROR) . PHP_EOL;
            // Business diagnostics have no framework access-log sink. Keep a bounded,
            // exportable file instead of PHP's stderr (the user's gateway console).
            $directory = (string) Config::get('gateway', 'storage_log_dir');
            if ($directory === '') return;
            if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) return;
            $path = $directory . '/gateway-storage.log';
            $lock = @fopen($path . '.lock', 'c');
            if ($lock === false) return;
            try {
                // Never delay a result or hold a database transaction for log contention.
                if (!flock($lock, LOCK_EX | LOCK_NB)) return;
                clearstatcache(true, $path);
                if (is_file($path) && (int) @filesize($path) >= 1048576) {
                    if (is_file($path . '.1') && !@unlink($path . '.1')) return;
                    if (!@rename($path, $path . '.1')) return;
                }
                @file_put_contents($path, $line, FILE_APPEND);
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        } catch (\Throwable) {
            // Diagnostics cannot alter a committed result or replace the original failure.
        }
    }

    /**
     * Acquire a writer before any callback can run. SQLite's busy handler is not a fair
     * queue, so competing workers get at most three five-second waits with jitter between
     * them. A failed BEGIN has no effects to replay; every later failure remains final.
     * @throws GatewayError When no transaction can be acquired or the driver fails silently.
     * @throws \PDOException For non-contention errors, including an invalid nested BEGIN.
     */
    private static function beginWriter(object $db): \PDOStatement
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                $statement = $db->query('BEGIN IMMEDIATE');
            } catch (\PDOException $error) {
                $sqliteCode = (int) ($error->errorInfo[1] ?? 0) & 0xff;
                if (!in_array($sqliteCode, [5, 6], true)) throw $error;
                if ($attempt >= self::WRITER_LOCK_ATTEMPTS) {
                    throw new GatewayError('STORAGE_BUSY', '本地数据库写入繁忙，请稍后重试。', 503);
                }
                // Release the failed driver's exception/statement references before another
                // BEGIN. No operation or COMMIT is retried by this loop.
                unset($error);
                usleep(random_int(20000, 60000));
                continue;
            }
            if (!$statement instanceof \PDOStatement) {
                throw new GatewayError('STORAGE_UNAVAILABLE', '本地数据库无法开始写入，请检查运行状态。', 503);
            }
            return $statement;
        }
    }
}
