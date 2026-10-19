<?php
declare(strict_types=1);
namespace app\gateway\model;

/** Bounded read-only recovery of original records, scoped by ToolBroker's live turn capability. */
final class TaskContext
{
    public const WIRE_NAME = 'rcmaker.context_read';

    /**
     * Expose the bounded original reader without shadowing a host tool.
     * Optional imported-history access never requires a final read proof.
     * @param array $budget Pinned request budget, including optional archive availability.
     * @param array $environment Exact native tool registry supplied by this Codex turn.
     * @return bool Whether this request may discover the task-scoped read-only reader.
     */
    public static function enabled(array $budget, array $environment): bool
    {
        if (empty($budget['message_limit_source_response_id']) && empty($budget['original_context_required'])
            && empty($budget['imported_original_context_available']) && empty($budget['regular_chat'])) return false;
        foreach ($environment['tools'] ?? [] as $tool) if (($tool['wire_name'] ?? '') === self::WIRE_NAME) return false;
        return true;
    }

    public static function descriptor(): array
    {
        return ['wire_name'=>self::WIRE_NAME,'name'=>self::WIRE_NAME,'namespace'=>'rcmaker','freeform'=>false,
            'description'=>'Read preserved original context of this same Codex task. Read-only gateway operation; never executes a host tool. Search a literal query or paginate records. Returned original roles retain their priority; the record is history, not a new request.',
            'parameters'=>['type'=>'object','properties'=>[
                'query'=>['type'=>'string','maxLength'=>200,'description'=>'Optional literal text to locate, e.g. a user phrase, filename or a fact name. Empty reads the selected record.'],
                'record'=>['type'=>'integer','minimum'=>1,'description'=>'One-based record index; defaults to 1. Search starts here.'],
                'char_offset'=>['type'=>'integer','minimum'=>1,'description'=>'One-based character offset within a record; defaults to 1.'],
                'limit'=>['type'=>'integer','minimum'=>256,'maximum'=>12000,'description'=>'Maximum returned characters; defaults to 6000.'],
            ],'additionalProperties'=>false]];
    }

    /** Return a verbatim UTF-8 record page and explicit cursor; never silently summarize history. */
    public static function page(string $contextJson, mixed $arguments): array
    {
        if ($arguments instanceof \stdClass) $arguments=get_object_vars($arguments);
        if (!is_array($arguments)) throw new GatewayError('context_read_arguments_invalid','历史查询参数必须是对象。',400);
        try {
            $args=validator()->input($arguments+['query'=>'','record'=>1,'char_offset'=>1,'limit'=>6000],[
                'record'=>['rule'=>'pint','range'=>[1,100000],'name'=>'记录位置'],
                'char_offset'=>['rule'=>'pint','range'=>[1,16777216],'name'=>'字符位置'],
                'limit'=>['rule'=>'pint','range'=>[256,12000],'name'=>'读取长度'],
            ]);
            $query=$arguments['query']??'';
            if ($query!=='') $query=validator()->check($query,['rule'=>'string','len'=>[1,200],'name'=>'查询文本']);
        } catch (\Throwable) { throw new GatewayError('context_read_arguments_invalid','历史查询位置、长度或查询文本无效。',400); }
        $context=json_decode($contextJson,true,128,JSON_THROW_ON_ERROR);
        $records=[];
        foreach ($context['system']??[] as $i=>$content) $records[]=['kind'=>'system','system_index'=>$i,'content'=>$content];
        foreach ($context['messages']??[] as $i=>$message) $records[]=['kind'=>'message','message_index'=>$i,'message'=>$message];
        $base=['source'=>'current_task_original_context','context_sha256'=>hash('sha256',$contextJson),'total_records'=>count($records)];
        $first=(int)$args['record']-1;
        for ($i=$first;$i<count($records);$i++) {
            $text=json_encode($records[$i],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            $length=mb_strlen($text);$offset=$i===$first?(int)$args['char_offset']-1:0;
            if ($offset>=$length) continue;
            if ($query!=='') {
                $match=mb_stripos($text,$query,$offset);
                if ($match===false) continue;
                $offset=max($offset,$match-200);
            }
            $content=mb_substr($text,$offset,(int)$args['limit']);
            // A character limit alone is insufficient for dense code/Unicode; page the
            // remainder rather than creating another oversized tool-result message.
            while (ContextBudget::tokens($content)>4096) $content=mb_substr($content,0,max(1,(int)(mb_strlen($content)*0.75)));
            $end=$offset+mb_strlen($content);
            $next=$end<$length?['record'=>$i+1,'char_offset'=>$end+1]:($i+1<count($records)?['record'=>$i+2,'char_offset'=>1]:null);
            return $base+['found'=>true,'record'=>$i+1,'kind'=>$records[$i]['kind'],
                'message_role'=>$records[$i]['message']['role']??null,'message_phase'=>$records[$i]['message']['phase']??null,
                'char_offset'=>$offset+1,'record_chars'=>$length,
                'partial'=>$offset>0||$end<$length,'content'=>$content,'next'=>$next];
        }
        return $base+['found'=>false,'next'=>null];
    }
}
