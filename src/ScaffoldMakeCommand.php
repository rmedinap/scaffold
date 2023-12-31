<?php

namespace Rmedina\Scaffold;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use PhpParser\Node\Stmt\Switch_;
use Schema;
use Symfony\Component\Console\Input\InputInterface;
use App\Models\User;
use App\Models\Permission;
use App\Models\Role;

class ScaffoldMakeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:scaffold {class} {fields*}
    {--route= : Append to specific route file, default is web.}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Make scaffolding resource.';

    private $config;

    public function __construct($config)
    {
        $this->config = $config;

        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $singular_plural_class = $this->explodeclass();

        $this->createRequest();
        $this->createMigration();
        $this->createModel();
        $this->createTable();

        $dir = "database/migrations";

        $newest_migration = $this->last_file($dir);

        $ruta_migracion = $dir . '/' . $newest_migration;

        $f=fopen($ruta_migracion, 'r+');

        $contenido = file_get_contents($ruta_migracion);

        $array_fields = $this->argument('fields');

        $has_many = preg_grep('/^hasMany:.*/', $array_fields);
        $belongs_to = preg_grep('/^belongsTo:.*/', $array_fields);
        $belongs_to_many = preg_grep('/^belongsToMany:.*/', $array_fields);

        $array_fields = array_diff($array_fields, $has_many);
        $array_fields = array_diff($array_fields, $belongs_to);
        $array_fields = array_diff($array_fields, $belongs_to_many);

        $enum_rule = "";
        $enum_rule_item = ""; // enum rules items

        foreach(array_reverse($array_fields) as $field) {
            $split_content = explode('$table->id();', $contenido);
            $column = explode(":", $field);
            $modificadores_array = array("u" => "unsigned", "i" => "index", "U" => "unique", "n" => "nullable", "c" => "comment");

            switch (count($column)) {
                case 5:
                    $modificadores = str_split($column[4]);
                    $insertar_modificadores="";
                    foreach ($modificadores as $modificador) {
                        $insertar_modificadores.="->".$modificadores_array[$modificador]."()";
                    }
                    if ($column[2]=="" && $column[3]!="") {
                        $insertar='            $table->'.$column[1].'(\'' . $column[0] . '\')->default('.$column[3].')'.$insertar_modificadores.';';
                    } elseif ($column[2]=="" && $column[3]=="") {
                        $insertar='            $table->'.$column[1].'(\'' . $column[0] . '\')'.$insertar_modificadores.';';
                    } elseif ($column[2]!="" && $column[3]=="") {
                        $insertar='            $table->'.$column[1].'(\'' . $column[0] . '\', '.$column[2].')'.$insertar_modificadores.';';
                    } else {
                        $insertar='            $table->'.$column[1].'(\'' . $column[0] . '\', '.$column[2].')->default('.$column[3].')'.$insertar_modificadores.';';
                    }
                    break;
                case 4:
                    if ($column[1]=="enum") {
                        $split_enum = explode(".",$column[3]);

                        $enum_rule = "use Illuminate\Validation\Rules\Enum;\n";

                        $enum_rule_item .= "\nenum ".Str::ucfirst($column[0]).": string\n{\n";

                        foreach ($split_enum as $item) {
                            $enum_rule_item .= "    case ".$item." = '".$item."';\n";
                        }

                        $enum_rule_item .= "}\n";

                        $array_enum = "['".implode("','", $split_enum)."']";

                        $insertar='            $table->'.$column[1].'(\'' . $column[0] . '\','.$array_enum.');';

                    } else {
                        if ($column[2]=="") {
                            $insertar='            $table->'.$column[1].'(\'' . $column[0] . '\')->default('.$column[3].');';
                        } else {
                            $insertar='            $table->'.$column[1].'(\'' . $column[0] . '\', '.$column[2].')->default('.$column[3].');';
                        }
                    }
                    break;
                case 3:
                    $insertar='            $table->'.$column[1].'(\'' . $column[0] . '\', '.$column[2].');';
                    break;
                case 2:
                    $insertar='            $table->'.$column[1].'(\'' . $column[0] . '\');';
                    break;
                default:
                    $insertar='            $table->string(\'' . $column[0] . '\');';
                    break;
            }

            $contenido=$split_content[0].'$table->id();'.PHP_EOL.$insertar.$split_content[1];
        }

        $insertar = "            // Has Many".PHP_EOL;
        foreach ($has_many as $this_has_many) {
            $has = explode(":",$this_has_many);
            sleep(2);
            exec("php artisan make:migration add_".Str::lower($singular_plural_class[0])."_id_to_".Str::lower($has[1])." --table=".Str::plural(Str::lower($has[1])));

            $dir = "database/migrations";

            $newest_migration_foreign = $this->last_file($dir);

            $ruta_migracion_foreign = $dir . '/' . $newest_migration_foreign;

            $g=fopen($ruta_migracion_foreign, 'r+');

            $contenido_foreign = file_get_contents($ruta_migracion_foreign);

            $insertar_foreign_up='$table->integer(\''.Str::lower($singular_plural_class[0]).'_id\')->nullable()->unsigned()->after(\'id\');';
            $insertar_foreign_up.=PHP_EOL.'            $table->foreign(\''.Str::lower($singular_plural_class[0]).'_id\',\'fk_'.Str::lower($singular_plural_class[0]).'_id\')->references(\'id\')->on(\''.Str::lower($singular_plural_class[1]).'\')->onUpdate(\'CASCADE\')->onDelete(\'CASCADE\');';

            $insertar_foreign_down='$table->dropForeign(\'fk_'.Str::lower($singular_plural_class[0]).'_id\');';
            $insertar_foreign_down.=PHP_EOL.'            $table->dropColumn(\''.Str::lower($singular_plural_class[0]).'_id\');';

            $split_content = explode('//', $contenido_foreign);

            $contenido_foreign=$split_content[0].$insertar_foreign_up.$split_content[1].$insertar_foreign_down.$split_content[2];

            fwrite($g, $contenido_foreign);
        }

        $insertar = PHP_EOL."            // Belongs to".PHP_EOL;
        foreach ($belongs_to as $this_belong_to) {
            $split_content = explode('$table->timestamps();', $contenido);

            $belong = explode(":",$this_belong_to);
            $insertar .= '            $table->integer(\''.Str::plural(strtolower($belong[1])).'_id\')->unsigned()->index()->nullable();'.PHP_EOL;
            $insertar .= '            $table->foreign(\''.Str::plural(strtolower($belong[1])).'_id\')->references(\'id\')->on(\''.Str::plural(strtolower($belong[1])).'\');  '.PHP_EOL;

            $contenido=$split_content[0].'$table->timestamps();'.PHP_EOL.$insertar.$split_content[1];
        }

        $insertar = PHP_EOL."        // Belong to Many";
        foreach ($belongs_to_many as $this_belong_to) {
            $split_content = explode('});', $contenido);

            $belong = explode(":",$this_belong_to);

            $belong_mix = explode('-',$belong[1]);

            $mix = str_replace("-", "_", $belong[1]);

            $insertar .= PHP_EOL.'        Schema::create(\''.strtolower($mix).'\', function (Blueprint $table) {'.PHP_EOL;

            $insertar .= '            $table->increments(\'id\');'.PHP_EOL;

            $insertar .= '            $table->integer(\''.strtolower($belong_mix[0]).'_id\')->unsigned()->index();'.PHP_EOL;

            $insertar .= '            $table->foreign(\''.strtolower($belong_mix[0]).'_id\')->references(\'id\')->on(\''.Str::lower($singular_plural_class[1]).'\')->onDelete(\'cascade\');'.PHP_EOL;

            $insertar .= '            $table->integer(\''.strtolower($belong_mix[1]).'_id\')->unsigned()->index();'.PHP_EOL;

            $insertar .= '            $table->foreign(\''.strtolower($belong_mix[1]).'_id\')->references(\'id\')->on(\''.Str::plural(strtolower($belong_mix[1])).'\')->onDelete(\'cascade\');'.PHP_EOL;

            $insertar .= '        });'.PHP_EOL;

            $contenido=$split_content[0].'});'.PHP_EOL.$insertar.$split_content[1];
        }

        fwrite($f, $contenido);

        $migrar = $this->ask('Desea ejecutar la migration '.$ruta_migracion.' ([S,N]): ');

        if ($migrar=='' || $migrar=='S' || $migrar=='Si' || $migrar=='SI') {
            exec('php artisan migrate');
        } else {
            print "No se realizo la migracion.";
        }

        $this->updateDummyNameController();

        $modal = ["1" => "modal", "2" => "slideover"];

        $opcion = $this->ask('Elegir Modal(1) [Default] o Slideover (2): ');

        // echo (isset($modal[$opcion])?$modal[$opcion]:"modal");

        $this->createViews(isset($modal[$opcion])?$modal[$opcion]:"modal");

        $this->insertFactories();

        $power_joins = exec("composer show -- kirschbaum-development/eloquent-power-joins | grep 'version'");

        $this->insertModelCode($power_joins);

        $this->appendRouteFile();

        $paginacion = $this->ask('Elegir paginacion (25 por defecto): ');

        $this->insertTableCode(isset($paginacion) ? $paginacion : "25");

        $this->insertRequestCode($enum_rule, $enum_rule_item);

        $this->insertInMenu();

        $respuesta= $this->ask('Desea crear los permisos para '.Str::lower($singular_plural_class[0]).' (Enter para continuar): ');

        if ($respuesta=='' || $respuesta=='S' || $respuesta=='s' || $respuesta=='SI' || $respuesta=='si') $this->setPermissions(); else echo("No se crearon Permisos");
    }

    protected function makeViews($view, $modal)
    {
        $singular_plural_class = $this->explodeclass();

        $name = Str::snake(class_basename($singular_plural_class[1]));

        $creating = file_get_contents(__DIR__ . '/stubs/' . $view . '.blade.stub');
        $array_fields = array_reverse(array_diff(Schema::getColumnListing($name), ["created_at", "updated_at"]));
        $html_fields = '';
        $html_show_fields = '';
        $html_related_fields = '';

        foreach ($array_fields as $field) {
            if ($field != 'id' && !strpos($field, '_id')) {
                $html_fields .= '                                <x-splade-input name="' . $field . '" label="' . $field . '" type="text" class="mb-5" />'.PHP_EOL;
            }
            $html_show_fields .= '                                    <p>{{ $'.Str::snake(class_basename($singular_plural_class[0])).'->' . $field . ' }} </p>'.PHP_EOL;
        }

        // Introduciendo la tabla relacionada correspondiente a los hasMany y belongsToMany

        $array_arguments = $this->argument('fields');

        $has_many = preg_grep('/^hasMany:.*/', $array_arguments);
        $belongs_to = preg_grep('/^belongsTo:.*/', $array_arguments);
        $belongs_to_many = preg_grep('/^belongsToMany:.*/', $array_arguments);

        foreach ($has_many as $this_has_many) {
            $has = explode(":",$this_has_many);
            $singular_hm_related_model = Str::lower($has[1]);
            $plural_hm_related_model = Str::plural(Str::lower($has[1]));
            $singular_hm_this_model = Str::snake(class_basename($singular_plural_class[0]));
            $html_related_fields .= '        <x-splade-select name="'.$plural_hm_related_model.'[]" placeholder="Select '.$plural_hm_related_model.'" multiple choices relation label="Select '.$plural_hm_related_model.'">'.PHP_EOL;
            $html_related_fields .= '            @foreach ($'.$plural_hm_related_model.' as $'.$singular_hm_related_model.')'.PHP_EOL;
            $html_related_fields .= '                <option value="{{ $'.$singular_hm_related_model.'->id }}">'.PHP_EOL;
            $html_related_fields .= '                    {{ $'.$singular_hm_related_model.'->'.$has[3].' }}'.PHP_EOL;
            $html_related_fields .= '                </option>'.PHP_EOL;
            $html_related_fields .= '            @endforeach'.PHP_EOL;
            $html_related_fields .= '        </x-splade-select>'.PHP_EOL;
        }

        foreach ($belongs_to as $this_belongs_to) {
            $has = explode(":",$this_belongs_to);
            $singular_b2_related_model = Str::lower($has[1]);
            $plural_b2_related_model = Str::lower($has[2]);
            $html_related_fields .= '        <x-splade-select name="'.$plural_b2_related_model.'[]" placeholder="Select '.$singular_b2_related_model.'" choices  relation label="Select '.$singular_b2_related_model.'">'.PHP_EOL;
            $html_related_fields .= '            @foreach ($'.$plural_b2_related_model.' as $'.$singular_b2_related_model.')'.PHP_EOL;
            $html_related_fields .= '                <option value="{{ $'.$singular_b2_related_model.'->id }}">'.PHP_EOL;
            $html_related_fields .= '                    {{ $'.$singular_b2_related_model.'->'.$has[3].' }}'.PHP_EOL;
            $html_related_fields .= '                </option>'.PHP_EOL;
            $html_related_fields .= '            @endforeach'.PHP_EOL;
            $html_related_fields .= '        </x-splade-select>'.PHP_EOL;
        }

        foreach ($belongs_to_many as $this_many_to_many) {
            $has = explode(":",$this_many_to_many);
            $singular_m2m_related_model = Str::lower($has[2]);
            $plural_m2m_related_model = Str::plural(Str::lower($has[2]));
            $singular_m2m_this_model = Str::snake(class_basename($singular_plural_class[0]));
            $html_related_fields .= '        <x-splade-select name="'.$plural_m2m_related_model.'[]" placeholder="Select '.$plural_m2m_related_model.'" multiple choices relation label="Select '.$plural_m2m_related_model.'">'.PHP_EOL;
            $html_related_fields .= '            @foreach ($'.$plural_m2m_related_model.' as $'.$singular_m2m_related_model.')'.PHP_EOL;
            $html_related_fields .= '                <option value="{{ $'.$singular_m2m_related_model.'->id }}">'.PHP_EOL;
            $html_related_fields .= '                    {{ $'.$singular_m2m_related_model.'->'.$has[3].' }}'.PHP_EOL;
            $html_related_fields .= '                </option>'.PHP_EOL;
            $html_related_fields .= '            @endforeach'.PHP_EOL;
            $html_related_fields .= '        </x-splade-select>'.PHP_EOL;
        }

        if (! is_dir(base_path('resources/views/' . $name))) {
            mkdir(base_path('resources/views/' . $name));
        }

        touch(base_path('resources/views/' . $name . '/' . $view . '.blade.php'));

        $titulo = preg_replace('/([a-z])([A-Z])/s','$1 $2', $singular_plural_class[0]);

        $cstep2 = str_replace("PluralSnakeClass", $name, $creating);
        $cstep3 = str_replace("SnakeClass", Str::snake(class_basename($singular_plural_class[0])), $cstep2);
        $cstep4 = str_replace("FormFields", $html_fields, $cstep3);
        $cstep5 = str_replace("RelatedFields", $html_related_fields, $cstep4);
        $cstep6 = str_replace("ShowFields", $html_show_fields, $cstep5);
        $cstep7 = str_replace("DummyTitleClass", $titulo, $cstep6);
        $cstep8 = str_replace("DummyLowerClass", Str::lower($singular_plural_class[0]), $cstep7);

        $cstep9 = str_replace("slideover", $modal, $cstep8);
        $created = str_replace("DummyClass", $singular_plural_class[0], $cstep9);

        file_put_contents(base_path('resources/views/' . $name . '/' . $view . '.blade.php'), $created);
    }

    /**
     * @deprecated
     */
    protected function createFactory()
    {
        $singular_plural_class = $this->explodeclass();

        $this->call('make:factory', [
            'name' => $singular_plural_class[0].'Factory',
            '--model' => $singular_plural_class[0],
        ]);
    }

    /**
     * @deprecated
     */
    public function createMigration()
    {
        $singular_plural_class = $this->explodeclass();

        $table = Str::snake($singular_plural_class[1]);

        // $this->call('make:migration', [
        //     'name' => "create_{$table}_table".$this->argument('fields'),
        //     '--create' => $table,
        // ]);

        $output = shell_exec('php artisan make:migration create_'.$table.'_table --create='.$table);
        // print_r($this->argument('fields'));
    }

    /**
     * @deprecated
     */
    protected function createController()
    {
        $singular_plural_class = $this->explodeclass();

        $controller = Str::studly(class_basename($singular_plural_class[0]));

        $this->call('make:controller', [
            'name' => "{$controller}Controller",
            '-r' => true,
        ]);
    }

    protected function createModel()
    {
        $singular_plural_class = $this->explodeclass();

        // $array_fields = $this->argument('fields');

        $this->call('make:model', [
            'name' => $singular_plural_class[0],
            '-c' => true,
            '-R' => true,
            '-f' => true,
            '-r' => true,
        ]);

        // exit(print_r($array_fields));
    }

    protected function createRequest()
    {
        $singular_plural_class = $this->explodeclass();

        $request = Str::studly(class_basename($singular_plural_class[0]));

        $this->call('make:request', [
            'name' => "{$request}Request"
        ]);
    }

    protected function createTable()
    {
        $singular_plural_class = $this->explodeclass();

        $this->call('make:table', [
            'name' => $singular_plural_class[1]
        ]);
    }

    protected function updateDummyNameController()
    {
        $singular_plural_class = $this->explodeclass();

        $name = Str::snake(class_basename($singular_plural_class[1]));

        // Listado de campos del Modelo/Schema.
        $array_fields = array_reverse(array_diff(Schema::getColumnListing($name), ["created_at", "updated_at"]));
        $validate_data_fields = '';
        $create_data_fields = '';

        foreach ($array_fields as $field) {
            $validate_data_fields .= '                                \'' . $field . '\' => \'required\','.PHP_EOL;
            $create_data_fields .= '                                \'' . $field . '\' => request()->post(\'' . str_replace("_id", "",$field) . '\'),'.PHP_EOL;
        }

        // Introduciendo la tabla relacionada correspondiente a los hasMany y belongsToMany

        $array_arguments = $this->argument('fields');

        $has_many = preg_grep('/^hasMany:.*/', $array_arguments);
        $belongs_to = preg_grep('/^belongsTo:.*/', $array_arguments);
        $belongs_to_many = preg_grep('/^belongsToMany:.*/', $array_arguments);

        $related_table_hm_all = "";
        $sync_related_table_hm = "";
        $dummy_related_class = "";
        $compact_related_table_create = ", compact(";
        $compact_related_table_edit = "";

        $i=0;

        foreach ($has_many as $this_has_many) {
            $has = explode(":",$this_has_many);
            $plural_hm_related_model = Str::plural(Str::lower($has[1]));
            $singular_m2m_this_model = Str::snake(class_basename($singular_plural_class[0]));
            // $users = User::all();
            $related_table_hm_all .= '$'.$plural_hm_related_model.' = '.$has[1].'::all();'.PHP_EOL;
            //$chatroom->users()->sync($request->users);
            //$unidade->users()->saveMany(User::find($request->users));
            $sync_related_table_hm .= '$'.$singular_m2m_this_model.'->'.$plural_hm_related_model.'()->saveMany('.$has[1].'::find($request->'.$plural_hm_related_model.'));'.PHP_EOL;
            $compact_related_table_create .= ($i==0)?'\''.$plural_hm_related_model.'\'':', \''.$plural_hm_related_model.'\'';
            $compact_related_table_edit .= ', \''.$plural_hm_related_model.'\'';
            $dummy_related_class .= "use App"."\\"."Models"."\\".$has[1].";".PHP_EOL;
            $i++;
        }

        $j=0;

        foreach ($belongs_to as $this_belong_to) {
            $has = explode(":",$this_belong_to);
            $plural_hm_related_model = Str::plural(Str::lower($has[1]));
            $singular_m2m_this_model = Str::snake(class_basename($singular_plural_class[0]));


            // $users = User::all();
            $related_table_hm_all .= '$'.$plural_hm_related_model.' = '.$has[1].'::all();'.PHP_EOL;
            //$chatroom->users()->sync($request->users);
            //$unidade->users()->saveMany(User::find($request->users));
            $sync_related_table_hm .= '$'.$singular_m2m_this_model.'->'.$plural_hm_related_model.'()->saveMany('.$has[1].'::find($request->'.$plural_hm_related_model.'));'.PHP_EOL;
            $compact_related_table_create .= ($j==0)?'\''.$plural_hm_related_model.'\'':', \''.$plural_hm_related_model.'\'';
            $compact_related_table_edit .= ', \''.$plural_hm_related_model.'\'';
            $dummy_related_class .= "use App"."\\"."Models"."\\".$has[1].";".PHP_EOL;
            $j++;
        }

        $related_table_m2m_all = "";
        $sync_related_table_m2m = "";

        $k=0;

        foreach ($belongs_to_many as $this_many_to_many) {
            $has = explode(":",$this_many_to_many);
            $plural_m2m_related_model = Str::plural(Str::lower($has[2]));
            $singular_m2m_this_model = Str::snake(class_basename($singular_plural_class[0]));
            // $users = User::all();
            $related_table_m2m_all .= '$'.Str::plural(Str::lower($has[2])).' = '.$has[2].'::all();'.PHP_EOL;
            //$chatroom->users()->sync($request->users);
            $sync_related_table_m2m .= '$'.$singular_m2m_this_model.'->'.$plural_m2m_related_model.'()->sync($request->'.$plural_m2m_related_model.');';
            $compact_related_table_create .= ($k==0)?'\''.$plural_m2m_related_model.'\'':', \''.$plural_m2m_related_model.'\'';
            $compact_related_table_edit .= ', \''.$plural_m2m_related_model.'\'';
            $dummy_related_class .= "use App"."\\"."Models"."\\".$has[2].";".PHP_EOL;
            $k++;
        }

        $compact_related_table_create .= ")";

        // Si es hasMany o belongtoMany o belongsTo dejar como esta el $compact_related_table_create
        // caso contrario dejar en blanco

        if ($i+$j+$k == 0) {
            $compact_related_table_create = "";
        }

        // taking scaffold stub.
        $source = file_get_contents(__DIR__ . '/stubs/controller.scaffold.stub');

        // replace dummy name.
        $step1 = str_replace("DummyProp", Str::snake(class_basename($singular_plural_class[0])), $source);
        $step2 = str_replace("DummyAuthClass", Str::lower($singular_plural_class[0]), $step1);
        $step3 = str_replace("DummyLowerClass", $name, $step2);
        $step4 = str_replace("DummyClasses", $singular_plural_class[1], $step3);
        $step5 = str_replace("DummyClass", $singular_plural_class[0], $step4);
        $step6 = str_replace("ValidateDataFields", $validate_data_fields, $step5);
        $step7 = str_replace("CreateDataFields", $create_data_fields, $step6);
        $step8 = str_replace("RelatedTableHmAll", $related_table_hm_all, $step7);
        $step9 = str_replace("RelatedTableM2mAll", $related_table_m2m_all, $step8);
        $step10 = str_replace("SyncRelatedTableHm", $sync_related_table_hm, $step9);
        $step11 = str_replace("SyncRelatedTableM2m", $sync_related_table_m2m, $step10);
        $step12 = str_replace("CompactRelatedTableCreate", $compact_related_table_create, $step11);
        $step13 = str_replace("CompactRelatedTableEdit", $compact_related_table_edit, $step12);
        $step14 = str_replace("DummyRelatedClass", $dummy_related_class, $step13);

        // put in controller
        $controller = Str::studly(class_basename($singular_plural_class[0]));
        file_put_contents(base_path($this->config['path']['controller'] . '/' . "{$controller}Controller.php"), $step14);
    }

    protected function createViews($modal="slideover")
    {
        $this->makeViews('create',$modal);
        $this->makeViews('show',$modal);
        $this->makeViews('edit',$modal);
        $this->makeViews('index',$modal);
    }

    protected function appendRouteFile()
    {
        $singular_plural_class = $this->explodeclass();

        $name = Str::snake(class_basename($singular_plural_class[1]));
        $name_singular = Str::snake(class_basename($singular_plural_class[0]));
        $controller = Str::studly(class_basename($singular_plural_class[0]));

        $rroute_stub = file_get_contents(__DIR__ . '/stubs/routes.stub');
        $rstep2 = str_replace("PluralSnakeClass", $name, $rroute_stub);
        $rstep3 = str_replace("DummyLowerClass", $name_singular, $rstep2);
        $route_stub = str_replace("ControllerClass", "{$controller}Controller", $rstep3);

        file_put_contents(
            base_path('routes/' . ($this->option('route') ?: 'splade') . '.php'),
            $route_stub,
            FILE_APPEND
        );
    }

    protected function insertFactories()
    {
        $singular_plural_class = $this->explodeclass();

        $dir = "database/factories";

        $newest_factory = $this->last_file($dir);

        $ruta_factory = $dir . '/' . $newest_factory;

        $f=fopen($ruta_factory, 'r+');

        $contenido = file_get_contents($ruta_factory);

        $array_fields = $this->argument('fields');

        $has_many = preg_grep('/^hasMany:.*/', $array_fields);
        $belongs_to = preg_grep('/^belongsTo:.*/', $array_fields);
        $belongs_to_many = preg_grep('/^belongsToMany:.*/', $array_fields);

        $array_fields = array_diff($array_fields, $has_many);
        $array_fields = array_diff($array_fields, $belongs_to);
        $array_fields = array_diff($array_fields, $belongs_to_many);

        $basic_fake_value_array = [
            "string" => 'text($maxNbChars = 20)',
            "integer" => "randomNumber(1, 10)",
            "bigInteger" => "randomNumber(1, 10000)",
            "float" => "randomFloat(NULL, 1, 10)",
            "boolean" => "randomElement(True, False]",
            "date" => "dateTime()",
        ];

        foreach(array_reverse($array_fields) as $field) {
            $split_content = explode('//', $contenido);
            $column = explode(":", $field);

            if ( in_array($column[0],["nombre","name"]) ) {
                    $insertar = '            \''.$column[0].'\' => $this->faker->name,';
                } else if ( in_array($column[0],["denominacion","descripcion","texto","description"]) ) {
                    $insertar = '            \''.$column[0].'\' => $this->faker->text($maxNbChars = '.(isset($column[2])?$column[2]:"255").'),';
                } else if ( in_array($column[0],["email","mail","correo"]) ) {
                    $insertar = '            \''.$column[0].'\' => $this->faker->email,';
                } else if ( in_array($column[0],["telefono","phone","celular"]) ) {
                    $insertar = '            \''.$column[0].'\' => $this->faker->e164PhoneNumber,';
                } else if ( in_array($column[0],["direccion","address","ubicacion"]) ) {
                    $insertar = '            \''.$column[0].'\' => $this->faker->streetAddress,';
                } else if ( in_array($column[0],["longitude","long","lon"]) ) {
                    $insertar = '            \''.$column[0].'\' => $this->faker->longitude,';
                } else if ( in_array($column[0],["latitude","lat"]) ) {
                    $insertar = '            \''.$column[0].'\' => $this->faker->latitude,';
                } else if ( in_array($column[0],["ciudad","city"]) ) {
                    $insertar = '            \''.$column[0].'\' => $this->faker->city,';
                } else if ( in_array($column[0],["pais","country"]) ) {
                    $insertar = '            \''.$column[0].'\' => $this->faker->country,';
                } else if ( in_array($column[0],["orden"]) ) {
                    $insertar = '            \''.$column[0].'\' => $this->faker->randomNumber(1, 100),';
                } else if ( in_array($column[0],["codigo","code"]) ) {
                    $insertar = '            \''.$column[0].'\' => $this->faker->regexify(\'[A-Z0-9]{'.(isset($column[2])?$column[2]:"255").'}\'),';
                } else if ( in_array($column[0],["username","nombreusuario","nombre_usuario"]) ) {
                    $insertar = '            \''.$column[0].'\' => $this->faker->userName,';
                } else if ( isset($column[2]) ) {
                    if ( isset($column[1]) ) {
                        if ($column[1] == 'string') {
                            if (intval($column[1]) > 4) {
                                $insertar = '            \''.$column[0].'\' => $this->faker->text($maxNbChars = '.(isset($column[2])?$column[2]:"255").'),';
                            } else {
                                $insertar = '            \''.$column[0].'\' => $this->faker->regexify(\'[A-Z0-9]{'.(isset($column[2])?$column[2]:"255").'}\'),';
                            }
                        } elseif ($column[1] == 'float'){
                            $insertar = '            \''.$column[0].'\' => $this->faker->randomFloat(NULL, 1, '.(isset($column[3])?$column[3]:"10").'),';
                        } elseif ($column[1] == 'enum'){
                            $split_enum = explode(".",$column[3]);
                            $array_enum = "['".implode("','", $split_enum)."']";
                            $insertar = '            \''.$column[0].'\' => $this->faker->randomElement('.$array_enum.'),';
                        }
                        else {
                            $insertar = '            \''.$column[0].'\' => $this->faker->' . $basic_fake_value_array[$column[1]] .',';
                        }
                    }
                } else $insertar='';

            $contenido=$split_content[0].'//'.PHP_EOL.$insertar.$split_content[1];
        }

        fwrite($f, $contenido);
    }


    protected function insertModelCode(string $power_joins)
    {
        $singular_plural_class = $this->explodeclass();

        $dir = "app/Models";

        $insertar = "";

        $newest_model = $this->last_file($dir);

        $ruta_model = $dir . '/' . $newest_model;

        $f=fopen($ruta_model, 'r+');

        $contenido = file_get_contents($ruta_model);

        $split_content = explode('}', $contenido);

        $array_fields = array_reverse($this->argument('fields'));
        $array_fields_not_reversed = $this->argument('fields');

        $has_many = preg_grep('/^hasMany:.*/', $array_fields);
        $belongs_to = preg_grep('/^belongsTo:.*/', $array_fields);
        $belongs_to_many = preg_grep('/^belongsToMany:.*/', $array_fields);

        $array_fields = array_diff($array_fields, $has_many);
        // $array_fields = array_diff($array_fields, $belongs_to);
        $array_fields = array_diff($array_fields, $belongs_to_many);

        foreach ($array_fields as $field) {
            $only_field = explode(":",$field);
            if (isset($only_field[1]) && isset($only_field[2]) && $only_field[0] == "belongsTo") {
                $insertar .= '"'.Str::lower($only_field[2]).'_id",';
            } else {
                $insertar .= '"'.$only_field[0].'",';
            }
        }

        $insertar = PHP_EOL.'    protected $table = \''.Str::snake($singular_plural_class[1]).'\';'.PHP_EOL.PHP_EOL.'    protected $fillable = ['.rtrim($insertar,",").'];';

        $insertar_externo = "";

        $insertar_externo = "";

        foreach($belongs_to as $key => $val) {
            $nombre = explode(":",$val);
            $clase_externa_b2 = $nombre[1];
            $insertar .= PHP_EOL.PHP_EOL.'    public function '.Str::plural(strtolower($nombre[1])).'() {'.PHP_EOL;
            $insertar .= '        return $this->belongsTo('.$clase_externa_b2.'::class);'.PHP_EOL.'    }';

            //Insertar en Modelo Externo

            $ruta_model_externo = $dir . '/' . $clase_externa_b2 . '.php';

            $insertar_externo .= PHP_EOL."    //Relacion con: ".$singular_plural_class[0].PHP_EOL;
            $insertar_externo .= PHP_EOL.'    public function '.Str::lower($singular_plural_class[1]).'(): HasMany {'.PHP_EOL;
            $insertar_externo .= '        return $this->hasMany('.$singular_plural_class[0].'::class);'.PHP_EOL.'    }';

            $g = fopen($ruta_model_externo, 'r+');

            $contenido_externo = file_get_contents($ruta_model_externo);

            $last_mustache_pos = strrpos($contenido_externo, '}', -1) - 1;

            $contenido_externo = substr($contenido_externo,0,$last_mustache_pos).PHP_EOL.$insertar_externo.PHP_EOL."}";

            $eloquentHasMany = "use Illuminate\Database\Eloquent\Relations\HasMany;";

            $partes = explode("\nclass", $contenido_externo);

            $contenido_externo = $partes[0].$eloquentHasMany.PHP_EOL.PHP_EOL."class".$partes[1];

            fwrite($g, $contenido_externo);
        }

        //class Driver{public function cars(){return $this->belongsToMany(Car::class);}}
        //class Car{public function drivers(){return $this->belongsToMany(Driver::class);}}

        foreach($has_many as $key => $val) {
            $nombre = explode(":",$val);
            $insertar .= PHP_EOL.PHP_EOL.'    public function '.Str::plural(strtolower($nombre[1])).'() {'.PHP_EOL;
            $insertar .= '        return $this->hasMany('.$nombre[1].'::class);'.PHP_EOL.'    }';

            $insertar .= PHP_EOL.PHP_EOL."    //Insertar en Modelo: ".$nombre[1];
            $insertar .= PHP_EOL.'    public function '.Str::snake(class_basename($singular_plural_class[0])).'() {'.PHP_EOL;
            $insertar .= '        return $this->belongsTo('.class_basename($singular_plural_class[0]).'::class);'.PHP_EOL.'    }';
        }

        $insertar_externo = "";

        foreach($belongs_to_many as $key => $val) {
            $nombres = explode("-", explode(":",$val)[1]);
            $clase_externa_b2m = explode(":",$val)[2];
            $insertar .= PHP_EOL.PHP_EOL."    //Relacion con: ".$clase_externa_b2m;

            $insertar .= PHP_EOL.'    public function '.Str::plural(strtolower($nombres[1])).'() {'.PHP_EOL;
            $insertar .= '        return $this->belongsToMany('.$nombres[1].'::class);'.PHP_EOL.'    }';

            //Insertar en Modelo Externo

            $ruta_model_externo = $dir . '/' . $clase_externa_b2m . '.php';

            $insertar_externo .= PHP_EOL."    //Relacion con: ".$singular_plural_class[0].PHP_EOL;
            $insertar_externo .= PHP_EOL.'    public function '.Str::lower($singular_plural_class[1]).'() {'.PHP_EOL;
            $insertar_externo .= '        return $this->belongsToMany('.$nombres[0].'::class);'.PHP_EOL.'    }';

            $g = fopen($ruta_model_externo, 'r+');

            $contenido_externo = file_get_contents($ruta_model_externo);

            $last_mustache_pos = strrpos($contenido_externo, '}', -1) - 1;

            $contenido_externo = substr($contenido_externo,0,$last_mustache_pos).PHP_EOL.$insertar_externo.PHP_EOL."}";

            fwrite($g, $contenido_externo);
        }

        $contenido=$split_content[0].$insertar.PHP_EOL."}";

        $split_content = explode('class ', $contenido);

        $insertar = '/**
* Class '.$singular_plural_class[0].'
*
* @package '.$dir.PHP_EOL;

        foreach ($array_fields_not_reversed as $field) {
            $only_field = explode(":",$field);
            if (isset($only_field[1]) && isset($only_field[2]) && $only_field[0] == "belongsTo") {
                $insertar .= '* @property int '.$only_field[2].' A foreign key to an '.$only_field[1].PHP_EOL;
            } else {
                $insertar .= '* @property '.(isset($only_field[1])?$only_field[1]:"string").' '.$only_field[0].PHP_EOL;
            }
        }

        $contenido=$split_content[0].$insertar."*/".PHP_EOL."class ".$split_content[1];

        if ($power_joins != "") {
            $split_content = explode('use Illuminate\Database\Eloquent\Model;', $contenido);

            $insertar = "use Kirschbaum\PowerJoins\PowerJoins;";

            $contenido=$split_content[0].'use Illuminate\Database\Eloquent\Model;'.PHP_EOL.$insertar.$split_content[1];

            $split_content = explode('use HasFactory', $contenido);

            $insertar = ", PowerJoins";

            $contenido=$split_content[0].'use HasFactory'.$insertar.$split_content[1];
        }

        fwrite($f, $contenido);
    }



    protected function insertTableCode($paginacion = "25")
    {
        $singular_plural_class = $this->explodeclass();

        $dir = "app/Tables";

        $insertar = "";

        $newest_table = $this->last_file($dir);

        $ruta_table = $dir . '/' . $newest_table;

        $f=fopen($ruta_table, 'r+');

        $contenido = file_get_contents($ruta_table);

        //$pattern = '/App\\\Models\\\[A-Z][a-z]+/';
        $pattern = '/App\\\Models\\\([A-Z][a-z]+)+/';
        $replacement = 'App\\Models\\'.$singular_plural_class[0];

        $contenido = preg_replace($pattern, $replacement, $contenido);

        //$pattern = '/[A-Z][a-z]+::query/';
        $pattern = '/([A-Z][a-z]+)+::query/';

        $replacement = $singular_plural_class[0]."::query";

        $contenido = preg_replace($pattern, $replacement, $contenido);

        $split_content = explode("['id'", $contenido);

        $array_fields = array_reverse($this->argument('fields'));

        foreach ($array_fields as $field) {
            $only_field = explode(":",$field);
            $campo_externo = (isset($only_field[1]) && isset($only_field[2]) && isset($only_field[3])) ? $only_field[3] : "id";

            if (isset($only_field[1]) && isset($only_field[2]) && ($only_field[0] == "belongsTo" || $only_field[0] == "hasMany" || $only_field[0] == "belongsToMany")) {

                if ($only_field[0] == "belongsToMany") {
                    $insertar .= '"'.Str::plural(Str::snake(class_basename($only_field[2]))).'.'.$campo_externo.'",';
                } else {
                    $insertar .= '"'.Str::plural(Str::snake(class_basename($only_field[1]))).'.'.$campo_externo.'",';
                }

            } else {
                $insertar .= '\''.$only_field[0].'\',';
            }
        }

        $contenido=$split_content[0]."['id',".$insertar.$split_content[1];

        $split_content = explode("->column('id', sortable: true);", $contenido);

        $array_fields = array_reverse($this->argument('fields'));

        $insertar = "";

        foreach ($array_fields as $field) {
            $only_field = explode(":",$field);
            $campo_externo = (isset($only_field[1]) && isset($only_field[2]) && isset($only_field[3])) ? $only_field[3] : "id";

            if (isset($only_field[1]) && isset($only_field[2]) && ($only_field[0] == "belongsTo" || $only_field[0] == "hasMany" || $only_field[0] == "belongsToMany")) {

                if ($only_field[0] == "belongsToMany") {
                    $insertar .= PHP_EOL.'            ->column(\''.Str::plural(Str::snake(class_basename($only_field[2]))).'.'.$campo_externo.'\', sortable: true)';
                } else {
                    $insertar .= PHP_EOL.'            ->column(\''.Str::plural(Str::snake(class_basename($only_field[1]))).'.'.$campo_externo.'\', sortable: true)';
                }

            } else {
                $insertar .= PHP_EOL.'            ->column(\''.$only_field[0].'\', sortable: true)';
            }
        }

        $contenido=$split_content[0]."->column('id', sortable: true)".$insertar.PHP_EOL."            ->column(label: 'Actions', exportAs: false)".PHP_EOL."            ->paginate(".$paginacion.");".$split_content[1];

        fwrite($f, $contenido);
    }

    protected function insertRequestCode($enum_rule = "", $enum_rule_item = "") {
        $singular_plural_class = $this->explodeclass();

        $requestName = Str::studly(class_basename($singular_plural_class[0]));

        $dir = "app/Http/Requests";

        $insertar = "";
        $insertar_enums = "";

        $ruta_store_request = $dir . '/Store' . $requestName . 'Request.php';

        $f=fopen($ruta_store_request, 'r+');

        $contenido = file_get_contents($ruta_store_request);

        if ($enum_rule != "") {
            $split_content = explode("use Illuminate\Foundation\Http\FormRequest;", $contenido);
            $insertar_enums .= "use Illuminate\Foundation\Http\FormRequest;".PHP_EOL;
            $insertar_enums .= $enum_rule.PHP_EOL.$enum_rule_item.PHP_EOL;

            $contenido=$split_content[0].PHP_EOL.$insertar_enums.$split_content[1];
        }

        $split_content = explode("//", $contenido);

        $array_fields = array_reverse($this->argument('fields'));

        foreach ($array_fields as $field) {
            $only_field = explode(":",$field);

            $numerico = ["bigInteger","bigSerial","serial","float4","float8","int","int2","int4","int8","integer","decimal"];

            $tipo_dato = isset($only_field[1])?(in_array($only_field[1],$numerico)?"integer":$only_field[1]):"string";

            $longitud_dato = isset($only_field[2])?$only_field[2]:"255";

            switch ($tipo_dato) {
                case 'integer':
                    if ($only_field[0] != "belongsTo" && $only_field[0] != "hasMany" && $only_field[0] != "belongsToMany") {
                            $insertar .= '            \''.$only_field[0].'\' => [\'required\', \''.$tipo_dato.'\'],'.PHP_EOL;
                    } elseif ($only_field[0] != "hasMany" && $only_field[0] != "belongsToMany") {
                        $insertar .= '            \''.Str::lower($only_field[2]).'\' => [\'required\'],'.PHP_EOL;
                    }
                    break;

                case 'string':
                    if ($only_field[0] != "belongsTo" && $only_field[0] != "hasMany" && $only_field[0] != "belongsToMany") {
                        $insertar .= '            \''.$only_field[0].'\' => [\'required\', \''.$tipo_dato.'\', \'max:'.$longitud_dato.'\'],'.PHP_EOL;
                    } elseif ($only_field[0] != "hasMany" && $only_field[0] != "belongsToMany") {
                        $insertar .= '            \''.Str::lower($only_field[2]).'\' => [\'required\'],'.PHP_EOL;
                    }
                    break;

                case 'enum':
                    $insertar .= '            \''.$only_field[0].'\' => [\'required\', new Enum('.Str::ucfirst($only_field[0]).'::class)],'.PHP_EOL;
                    break;

                default:
                    # code...
                    break;
            }



        }

        $contenido=$split_content[0].PHP_EOL.$insertar.$split_content[1];

        $split_content = explode("use Illuminate\Foundation\Http\FormRequest;", $contenido);
        $insertar = "use Illuminate\Foundation\Http\FormRequest;".PHP_EOL."use Illuminate\Support\Facades\Gate;";
        $contenido=$split_content[0].$insertar.PHP_EOL.$split_content[1];

        $split_content = explode("return false;", $contenido);
        $insertar = "return Gate::allows('".Str::lower($singular_plural_class[0])."_create');";
        $contenido=$split_content[0].$insertar.PHP_EOL.$split_content[1];

        fwrite($f, $contenido);

        // echo $contenido;

        $insertar = "";

        $ruta_update_request = $dir . '/Update' . $requestName . 'Request.php';

        $insertar = "";
        $insertar_enums = "";

        $g=fopen($ruta_update_request, 'r+');

        $contenido2 = file_get_contents($ruta_update_request);

        if ($enum_rule != "") {
            $split_content = explode("use Illuminate\Foundation\Http\FormRequest;", $contenido2);
            $insertar_enums .= "use Illuminate\Foundation\Http\FormRequest;".PHP_EOL;
            $insertar_enums .= $enum_rule.PHP_EOL.$enum_rule_item.PHP_EOL;

            $contenido2=$split_content[0].PHP_EOL.$insertar_enums.$split_content[1];
        }

        $split_content = explode("//", $contenido2);

        $array_fields = array_reverse($this->argument('fields'));

        foreach ($array_fields as $field) {
            $only_field = explode(":",$field);

            $numerico = ["bigInteger","bigSerial","serial","float4","float8","int","int2","int4","int8","integer","decimal"];

            $tipo_dato = isset($only_field[1])?(in_array($only_field[1],$numerico)?"integer":$only_field[1]):"string";

            $longitud_dato = isset($only_field[2])?$only_field[2]:"255";
            if ($only_field[0] != "belongsTo" && $only_field[0] != "hasMany" && $only_field[0] != "belongsToMany") {
                if ($tipo_dato=="string") {
                    $insertar .= '            \''.$only_field[0].'\' => [\'required\', \''.$tipo_dato.'\', \'max:'.$longitud_dato.'\'],'.PHP_EOL;
                } else {
                    $insertar .= '            \''.$only_field[0].'\' => [\'required\', \''.$tipo_dato.'\'],'.PHP_EOL;
                }
            } elseif ($only_field[0] != "hasMany" && $only_field[0] != "belongsToMany") {
                $insertar .= '            \''.Str::lower($only_field[2]).'\' => [\'required\'],'.PHP_EOL;
            }
        }

        $contenido2=$split_content[0].PHP_EOL.$insertar.$split_content[1];

        $split_content = explode("use Illuminate\Foundation\Http\FormRequest;", $contenido2);
        $insertar = "use Illuminate\Foundation\Http\FormRequest;".PHP_EOL."use Illuminate\Support\Facades\Gate;";
        $contenido2=$split_content[0].$insertar.PHP_EOL.$split_content[1];

        $split_content = explode("return false;", $contenido2);
        $insertar = "return Gate::allows('".Str::lower($singular_plural_class[0])."_create');";
        $contenido2=$split_content[0].$insertar.PHP_EOL.$split_content[1];

        fwrite($g, $contenido2);
    }

    function setPermissions() {
        $singular_plural_class = $this->explodeclass();

        $usuario_admin=User::find(1);
        // $clase = Str::snake(class_basename($singular_plural_class[0]));
        $clase = Str::lower($singular_plural_class[0]);
        $permisos = ["_access", "_show", "_create", "_edit", "_delete"];
        foreach ($permisos as $permiso) {
            $title = $clase.$permiso;
            Permission::create([
                'title' => $title
            ]);
        }
        //Admin puede tener todos los permisos
        $admin_permissions = Permission::all();
        Role::findOrFail(1)->permissions()->sync($admin_permissions);

    }

    function insertInMenu() {
        $singular_plural_class = $this->explodeclass();

        $ruta_menu = "resources/views/layouts/navigation.blade.php";

        $ruta = Str::snake($singular_plural_class[0]);
        $clase = Str::snake($singular_plural_class[1]);
        $can = Str::lower($singular_plural_class[0]);
        $titulo = preg_replace('/([a-z])([A-Z])/s','$1 $2', $singular_plural_class[0]);

        $f=fopen($ruta_menu, 'r+');

        $contenido = file_get_contents($ruta_menu);

        $split_content = explode("<!-- Last Link -->", $contenido);

        $insertar = "
                        @can('".$can."_access')
                            <x-nav-link :href=\"route('".$clase.".index')\" :active=\"request()->routeIs('".$clase.".index')\">
                                {{ __('".$titulo."') }}
                            </x-nav-link>
                        @endcan
                        <!-- Last Link -->";

        $contenido=$split_content[0].$insertar.$split_content[1];

        $split_content = explode("<!-- Last Responsive Link -->", $contenido);

        $insertar = "
                @can('".$can."_access')
                    <x-responsive-nav-link :href=\"route('".$clase.".index')\" :active=\"request()->routeIs('".$clase.".index')\">
                        {{ __('".$titulo."') }}
                    </x-responsive-nav-link>
                @endcan
                <!-- Last Responsive Link -->";

        $contenido=$split_content[0].$insertar.$split_content[1];

        fwrite($f, $contenido);
    }

    // Funciones protegidas

    protected function last_file(String $dir) {
        $latest = array(); $latest["time"] = 0;
        foreach (array_diff(scandir($dir), array(".", "..")) AS $file) {
            if (filemtime($dir."/".$file) > $latest["time"]) {
                $latest["file"] = $file;
                $latest["time"] = filemtime($dir."/".$file);
            }
        }
        return $latest["file"];
    }

    protected function explodeclass() {
        $clase_completa = explode(":",$this->argument('class'));

        $singular = $clase_completa[0];

        $plural = isset($clase_completa[1])?$clase_completa[1]:$singular."s";

        return [$singular, $plural];
    }
}
