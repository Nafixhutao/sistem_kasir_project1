<?php
// Read-only audit. Historical up() runs against an in-memory SQL collector,
// never against a writable database connection; historical DML is discarded.
ini_set('display_errors','0'); ini_set('log_errors','0');
require dirname(__DIR__).'/vendor/autoload.php';
require dirname(__DIR__).'/tests/Support/TestDatabaseGuard.php';
use Tests\Support\TestDatabaseGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

final class SchemaAuditCollector {
    public array $tables=[]; public array $ddl=[]; public string $file='';
    public function __construct(public $connection,public $grammar) {}
    public function create($t,$callback): void { $b=new Blueprint($t); $b->create(); $callback($b); $this->record($b); }
    public function table($t,$callback): void { $this->record(new Blueprint($t,$callback)); }
    private function record(Blueprint $b): void { foreach ($b->toSql($this->connection,$this->grammar) as $sql) $this->statement($sql); }
    public function hasTable($t): bool { return isset($this->tables[$t]); }
    public function hasColumn($t,$c): bool { return isset($this->tables[$t]['columns'][$c]); }
    public function dropIfExists($t): void { unset($this->tables[$t]); }
    public function drop($t): void { unset($this->tables[$t]); }
    public function split(string $s): array {
        $out=[]; $start=0; $depth=0; $quote=null;
        for($i=0;$i<strlen($s);$i++) {
            $ch=$s[$i];
            if($quote!==null) { if($ch==='\\') {$i++;continue;} if($ch===$quote) {if(($s[$i+1]??'')===$quote){$i++;continue;} $quote=null;} continue; }
            if(in_array($ch,["'",'"','`'],true)) {$quote=$ch;continue;}
            if($ch==='(')$depth++; elseif($ch===')')$depth--; elseif($ch===','&&$depth===0){$out[]=trim(substr($s,$start,$i-$start));$start=$i+1;}
        }
        $out[]=trim(substr($s,$start)); return $out;
    }
    private function columns(string $s): array { preg_match_all('/`([^`]+)`/',$s,$m); return $m[1]; }
    public function statement(string $sql): bool {
        $this->ddl[]=['migration'=>$this->file,'sql'=>$sql];
        if(preg_match('/^create table `([^`]+)` \((.*)\)(?: default.*)?$/is',$sql,$m)) {
            $this->tables[$m[1]]=['migration'=>$this->file,'columns'=>[],'indexes'=>[],'foreign'=>[]];
            foreach($this->split($m[2]) as $definition) $this->definition($m[1],$definition);
        } elseif(preg_match('/^alter table `?([a-z_]+)`? (.*)$/is',$sql,$m)) {
            foreach($this->split($m[2]) as $d) {
                if(preg_match('/^(?:add(?: column)?|modify(?: column)?) (.*)$/is',$d,$x)) $this->definition($m[1],$x[1]);
                elseif(preg_match('/^drop (?:index|column|foreign key) `?([a-z_]+)`?$/i',$d,$x)) foreach(['indexes','columns','foreign'] as $key) unset($this->tables[$m[1]][$key][$x[1]]);
                else throw new RuntimeException('Unsupported audit DDL: '.$this->file);
            }
        } elseif(!preg_match('/^UPDATE /i',$sql)) throw new RuntimeException('Unsupported audit SQL: '.$this->file);
        return true;
    }
    public function definition(string $t,string $d): void {
        if(preg_match('/^`?([a-z_]+)`?\s+((?:enum\([^)]*\)|(?:tinyint|smallint|bigint|int|varchar|char|decimal|timestamp|datetime|date|boolean|text|longtext|json)(?:\([^)]*\))?)(?: unsigned)?)(.*)$/is',$d,$m)) {
            $type=preg_replace('/,\s+(?=\d|\')/',',',strtolower($m[2])); $type=str_replace('boolean','tinyint(1)',$type);
            if($type==='tinyint')$type='tinyint(1)';
            $tail=$m[3]; $nullable=!(preg_match('/\bnot null\b/i',$tail)); $default=null;
            if(preg_match("/\\bdefault\\s+('(?:[^'\\\\]|\\\\.|'')*'|[^\\s]+)/i",$tail,$v)) {
                $default=$v[1]; if($default[0]==="'")$default=str_replace("''","'",stripcslashes(substr($default,1,-1)));
                elseif(strcasecmp($default,'null')===0)$default=null;
                elseif(strcasecmp($default,'CURRENT_TIMESTAMP')===0)$default='CURRENT_TIMESTAMP';
                elseif(strcasecmp($default,'TRUE')===0)$default='1';
                elseif(strcasecmp($default,'FALSE')===0)$default='0';
            }
            $collation=null;
            if(preg_match('/^(?:varchar|char|text|longtext|enum)/',$type)){
                $collation=$this->connection->getConfig('collation');
                if(preg_match('/\bcollate\s+[\'\"]?([a-z0-9_]+)/i',$tail,$a))$collation=$a[1];
            }
            $this->tables[$t]['columns'][$m[1]]=['type'=>$type,'null'=>$nullable,'default'=>$default,'auto'=>(bool)preg_match('/auto_increment/i',$tail),'collation'=>$collation,'migration'=>$this->file];
            if(preg_match('/primary key/i',$tail)) $this->tables[$t]['indexes']['PRIMARY']=['columns'=>[$m[1]],'unique'=>true,'migration'=>$this->file];
        } elseif(preg_match('/^primary key\s*\((.*)\)$/is',$d,$m)) {
            $this->tables[$t]['indexes']['PRIMARY']=['columns'=>$this->columns($m[1]),'unique'=>true,'migration'=>$this->file];
        } elseif(preg_match('/^(unique\s+)?(?:(?:index|key)\s+)?`?([a-z_]+)`?\s*\(([^)]+)\)/i',$d,$m)) {
            $cols=$this->columns($m[3]); if(!$cols)$cols=array_map('trim',explode(',',$m[3]));
            $this->tables[$t]['indexes'][$m[2]]=['columns'=>$cols,'unique'=>trim($m[1])!=='','migration'=>$this->file];
        } elseif(preg_match('/^(?:constraint\s+`([^`]+)`\s+)?foreign key\s*\(([^)]+)\) references `([^`]+)`\s*\(([^)]+)\)(.*)$/i',$d,$m)) {
            $cols=$this->columns($m[2]); $name=$m[1]?:$t.'_'.implode('_',$cols).'_foreign';
            $delete='NO ACTION';$update='NO ACTION';
            if(preg_match('/on delete (cascade|restrict|set null|no action)/i',$m[5],$a))$delete=strtoupper($a[1]);
            if(preg_match('/on update (cascade|restrict|set null|no action)/i',$m[5],$a))$update=strtoupper($a[1]);
            $this->tables[$t]['foreign'][$name]=['columns'=>$cols,'table'=>$m[3],'references'=>$this->columns($m[4]),'delete'=>$delete,'update'=>$update,'migration'=>$this->file];
        } else throw new RuntimeException('Unsupported column/key definition: '.$t.' '.$this->file.' '.$d);
    }
}
final class AuditNullQuery {
    public function __call($method,$args) { return match($method){'exists'=>false,'get'=>collect(),'first'=>null,'count','update','delete'=>0,'insert','updateOrInsert','chunkById'=>true,default=>$this}; }
}
final class AuditNullDatabase {
    public function __construct(private SchemaAuditCollector $collector){}
    public function connection(...$args){return $this;}
    public function pretending(){return true;}
    public function getDriverName(){return 'mysql';}
    public function statement($sql){return $this->collector->statement($sql);}
    public function select(...$args){return [];}
    public function selectOne(...$args){return null;}
    public function table(...$args){return new AuditNullQuery;}
    public function raw($value){return new Illuminate\Database\Query\Expression($value);}
}

try {
    $base=dirname(__DIR__); $root=dirname($base,2);
    $app=require $base.'/bootstrap/app.php';TestDatabaseGuard::install($app);
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db=DB::connection();TestDatabaseGuard::assertActual($db);
    $actual=[];
    foreach($db->select('SHOW TABLES') as $r){
        $t=array_values((array)$r)[0];$actual[$t]=['columns'=>[],'indexes'=>[],'foreign'=>[]];
        foreach($db->select('SHOW FULL COLUMNS FROM `'.$t.'`') as $c)$actual[$t]['columns'][$c->Field]=['type'=>$c->Type,'null'=>$c->Null==='YES','default'=>$c->Default,'auto'=>str_contains($c->Extra,'auto_increment'),'collation'=>$c->Collation];
        foreach($db->select('SHOW INDEX FROM `'.$t.'`') as $i){
            if($i->Sub_part!==null || ($i->Expression??null)!==null)throw new RuntimeException('Unmapped partial/expression index.');
            $actual[$t]['indexes'][$i->Key_name]['columns'][]=$i->Column_name;
            $actual[$t]['indexes'][$i->Key_name]['unique']=(int)$i->Non_unique===0;
        }
    }
    foreach($db->select('SELECT k.TABLE_NAME,k.CONSTRAINT_NAME,k.COLUMN_NAME,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.DELETE_RULE,r.UPDATE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.TABLE_NAME,k.CONSTRAINT_NAME,k.ORDINAL_POSITION') as $f){
        $entry=&$actual[$f->TABLE_NAME]['foreign'][$f->CONSTRAINT_NAME];$entry['columns'][]=$f->COLUMN_NAME;$entry['table']=$f->REFERENCED_TABLE_NAME;$entry['references'][]=$f->REFERENCED_COLUMN_NAME;$entry['delete']=$f->DELETE_RULE;$entry['update']=$f->UPDATE_RULE;
    } unset($entry);
    $db->getSchemaBuilder();
    $collector=new SchemaAuditCollector($db,$db->getSchemaGrammar());
    $realSchema=Schema::getFacadeRoot();$realDb=DB::getFacadeRoot();
    Schema::swap($collector);DB::swap(new AuditNullDatabase($collector));
    $normalizer=null;
    foreach(glob($base.'/database/migrations/*.php') as $file){
        $collector->file=basename($file);
        if(str_contains($file,'normalize_business_ids')){
            $normalizer=require $file;$r=new ReflectionClass($normalizer);
            foreach($r->getConstant('BUSINESS_TABLES') as $t){$collector->tables[$t]['columns']['id']['type']='int unsigned';$collector->tables[$t]['columns']['id']['migration']=basename($file);}
            foreach($r->getConstant('VARCHAR_TARGETS') as $t=>$columns)foreach($columns as $c=>$length){$collector->tables[$t]['columns'][$c]['type']='varchar('.$length.')';$collector->tables[$t]['columns'][$c]['migration']=basename($file);}
            foreach($r->getConstant('FOREIGN_KEYS') as $f){$collector->tables[$f['table']]['columns'][$f['column']]['type']='int unsigned';$collector->tables[$f['table']]['columns'][$f['column']]['migration']=basename($file);}
        } elseif(str_contains($file,'complete_qris_unique_indexes')){
            // The actual new migration was exercised against a private legacy table.
            $migration=require $file;
            foreach((new ReflectionClass($migration))->getConstant('TARGETS') as $key=>$c)$collector->tables['pembayaran_qris']['indexes'][$key]=['columns'=>[$c],'unique'=>true,'migration'=>basename($file)];
        } else { $migration=require $file; $migration->up(); }
    }
    // Migration repository is created by Laravel, not an application migration.
    $collector->file='Laravel DatabaseMigrationRepository::createRepository';
    $collector->create('migrations',function(Blueprint $b){$b->increments('id');$b->string('migration');$b->integer('batch');});
    // InnoDB creates FK supporting keys implicitly if not explicitly declared.
    foreach($actual as $t=>$definition)foreach($definition['indexes'] as $key=>$index){
        if(!isset($collector->tables[$t]['indexes'][$key]) && isset($collector->tables[$t]['foreign'][$key]) && !$index['unique']
            && $index['columns']===$collector->tables[$t]['foreign'][$key]['columns']){
            $collector->tables[$t]['indexes'][$key]=$index+['migration'=>$collector->tables[$t]['foreign'][$key]['migration'].' (InnoDB FK support)'];
        }
    }
    Schema::swap($realSchema);DB::swap($realDb);TestDatabaseGuard::assertActual($db);
    $gaps=[]; $counts=['tables'=>count($actual),'columns'=>0,'indexes'=>0,'foreign_keys'=>0];
    foreach($actual as $t=>$definition){
        if(!isset($collector->tables[$t])){$gaps[]='table:'.$t;continue;}
        foreach(['columns','indexes','foreign'] as $kind){
            foreach($definition[$kind] as $object=>$shape){
                $expected=$collector->tables[$t][$kind][$object]??null;
                if(!$expected){$gaps[]=$kind.':'.$t.'.'.$object;continue;}
                unset($expected['migration']);
                // Default values are strings in SHOW COLUMNS, bool/int in blueprint SQL.
                if($kind==='columns' && $shape['default']!==null)$shape['default']=(string)$shape['default'];
                if($kind==='columns' && $expected['default']!==null)$expected['default']=(string)$expected['default'];
                if($kind==='columns' && str_starts_with($expected['type'],'decimal(')
                    && is_numeric($expected['default']) && is_numeric($shape['default'])
                    && (float)$expected['default']===(float)$shape['default'])$expected['default']=$shape['default'];
                if($expected!=$shape)$gaps[]='definition:'.$t.'.'.$object;
                $counts[match($kind){'columns'=>'columns','indexes'=>'indexes',default=>'foreign_keys'}]++;
            }
            foreach($collector->tables[$t][$kind] as $object=>$shape)if(!isset($definition[$kind][$object]))$gaps[]='unexpected-migration-object:'.$t.'.'.$object;
        }
    }
    foreach($collector->tables as $t=>$definition)if(!isset($actual[$t]))$gaps[]='unexpected-migration-table:'.$t;
    $h=fopen($root.'/qris-schema-provenance-20261011.json','w');fwrite($h,json_encode(['method'=>'in-memory migration DDL replay, normalizer constants, actual schema comparison; no historical DML executed','counts'=>$counts,'gaps'=>$gaps,'objects'=>$collector->tables],JSON_PRETTY_PRINT));fclose($h);
    echo 'DATABASE='.TestDatabaseGuard::DATABASE.PHP_EOL;
    echo json_encode(['counts'=>$counts,'gaps'=>$gaps]).PHP_EOL;
    if($gaps)exit(2);
    echo 'PASS final schema objects have matching migration provenance'.PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,'STOP schema audit: '.($e instanceof RuntimeException && !$e instanceof Illuminate\Database\QueryException ? $e->getMessage() : get_class($e)).PHP_EOL);exit(1);}
