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
        $this->createRequest();
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
                    if ($column[2]=="") {
                        $insertar='            $table->'.$column[1].'(\'' . $column[0] . '\')->default('.$column[3].');';
                    } else {
                        $insertar='            $table->'.$column[1].'(\'' . $column[0] . '\', '.$column[2].')->default('.$column[3].');';
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
            $split_content = explode('$table->timestamps();', $contenido);

            $has = explode(":",$this_has_many);
            $insertar .= '            $table->integer(\''.$has[2].'\')->unsigned()->index()->nullable();'.PHP_EOL;
            $insertar .= '            $table->foreign(\''.$has[2].'\')->references(\'id\')->on(\''.Str::plural(strtolower($has[1])).'\');  '.PHP_EOL;

            $contenido=$split_content[0].'$table->timestamps();'.PHP_EOL.$insertar.$split_content[1];
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

            $insertar .= '            $table->foreign(\''.strtolower($belong_mix[0]).'_id\')->references(\'id\')->on(\''.Str::plural(strtolower($belong_mix[0])).'\')->onDelete(\'cascade\');'.PHP_EOL;

            $insertar .= '            $table->integer(\''.strtolower($belong_mix[1]).'_id\')->unsigned()->index();'.PHP_EOL;

            $insertar .= '            $table->foreign(\''.strtolower($belong_mix[1]).'_id\')->references(\'id\')->on(\''.Str::plural(strtolower($belong_mix[1])).'\')->onDelete(\'cascade\');'.PHP_EOL;

            $insertar .= '        });'.PHP_EOL;

            $contenido=$split_content[0].'});'.PHP_EOL.$insertar.$split_content[1];
        }

        fwrite($f, $contenido);

        $this->ask('Revisar migration (Enter para continuar): '.$ruta_migracion);

        exec('php artisan migrate');

        $this->updateDummyNameController();

        $modal = ["1" => "modal", "2" => "slideover"];

        $opcion = $this->ask('Elegir Modal(1) [Default] o Slideover (2): ');

        // echo (isset($modal[$opcion])?$modal[$opcion]:"modal");

        $this->createViews(isset($modal[$opcion])?$modal[$opcion]:"modal");

        $this->insertFactories();

        $this->insertModelCode();

        $this->appendRouteFile();

        $paginacion = $this->ask('Elegir paginacion (25 por defecto): ');

        $this->insertTableCode(isset($paginacion) ? $paginacion : "25");

        $this->insertRequestCode();

        $this->insertInMenu();

        $respuesta= $this->ask('Desea crear los permisos para '.Str::lower($this->argument('class')).' (Enter para continuar): ');

        if ($respuesta=='' || $respuesta=='S' || $respuesta=='s' || $respuesta=='SI' || $respuesta=='si') $this->setPermissions(); else echo("No se crearon Permisos");
    }

    protected function makeViews($view, $modal)
    {
        $name = Str::plural(Str::snake(class_basename($this->argument('class'))));

        $creating = file_get_contents(__DIR__ . '/stubs/' . $view . '.blade.stub');
        $array_fields = array_reverse(array_diff(Schema::getColumnListing($name), ["created_at", "updated_at"]));
        $html_fields = '';
        $html_show_fields = '';

        foreach ($array_fields as $field) {
            if ($field != 'id') {
                $html_fields .= '                                <x-splade-input name="' . $field . '" label="' . $field . '" type="text" class="mb-5" />'.PHP_EOL;
            }
            $html_show_fields .= '                                    <p>{{ $'.Str::snake(class_basename($this->argument('class'))).'->' . $field . ' }} </p>'.PHP_EOL;
        }

        if (! is_dir(base_path('resources/views/' . $name))) {
            mkdir(base_path('resources/views/' . $name));
        }

        touch(base_path('resources/views/' . $name . '/' . $view . '.blade.php'));

        $titulo = preg_replace('/([a-z])([A-Z])/s','$1 $2', $this->argument('class'));

        $cstep2 = str_replace("PluralSnakeClass", $name, $creating);
        $cstep3 = str_replace("SnakeClass", Str::snake(class_basename($this->argument('class'))), $cstep2);
        $cstep4 = str_replace("FormFields", $html_fields, $cstep3);
        //$cstep4 = str_replace("FormEditFields", $html_edit_fields, $cstep3);
        $cstep5 = str_replace("ShowFields", $html_show_fields, $cstep4);
        $cstep6 = str_replace("DummyTitleClass", $titulo, $cstep5);
        $cstep7 = str_replace("DummyLowerClass", Str::lower($this->argument('class')), $cstep6);

        $cstep8 = str_replace("slideover", $modal, $cstep7);
        $created = str_replace("DummyClass", $this->argument('class'), $cstep8);

        file_put_contents(base_path('resources/views/' . $name . '/' . $view . '.blade.php'), $created);
    }

    /**
     * @deprecated
     */
    protected function createFactory()
    {
        $this->call('make:factory', [
            'name' => $this->argument('class').'Factory',
            '--model' => $this->argument('class'),
        ]);
    }

    /**
     * @deprecated
     */
    protected function createMigration()
    {
        $table = Str::plural(Str::snake(class_basename($this->argument('class'))));


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
        $controller = Str::studly(class_basename($this->argument('class')));

        $this->call('make:controller', [
            'name' => "{$controller}Controller",
            '-r' => true,
        ]);
    }

    protected function createModel()
    {
        // $array_fields = $this->argument('fields');

        $this->call('make:model', [
            'name' => $this->argument('class'),
            '--all' => true,
        ]);

        // exit(print_r($array_fields));
    }

    protected function createRequest()
    {
        $request = Str::studly(class_basename($this->argument('class')));

        $this->call('make:request', [
            'name' => "{$request}Request"
        ]);
    }

    protected function createTable()
    {
        $this->call('make:table', [
            'name' => Str::plural($this->argument('class'))
        ]);
    }

    protected function updateDummyNameController()
    {
        $name = Str::plural(Str::snake(class_basename($this->argument('class'))));

        // Listado de campos del Modelo/Schema.
        $array_fields = array_reverse(array_diff(Schema::getColumnListing($name), ["created_at", "updated_at"]));
        $validate_data_fields = '';
        $create_data_fields = '';

        foreach ($array_fields as $field) {
            $validate_data_fields .= '                                \'' . $field . '\' => \'required\','.PHP_EOL;
            $create_data_fields .= '                                \'' . $field . '\' => request()->post(\'' . $field . '\'),'.PHP_EOL;
        }

        // taking scaffold stub.
        $source = file_get_contents(__DIR__ . '/stubs/controller.scaffold.stub');

        // replace dummy name.
        $step1 = str_replace("DummyProp", Str::snake(class_basename($this->argument('class'))), $source);
        $step2 = str_replace("DummyLowerClass", $name, $step1);
        $step3 = str_replace("DummyClass", $this->argument('class'), $step2);
        $step4 = str_replace("ValidateDataFields", $validate_data_fields, $step3);
        $step5 = str_replace("CreateDataFields", $create_data_fields, $step4);


        // put in controller
        $controller = Str::studly(class_basename($this->argument('class')));
        file_put_contents(base_path($this->config['path']['controller'] . '/' . "{$controller}Controller.php"), $step5);
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
        $name = Str::plural(Str::snake(class_basename($this->argument('class'))));
        $name_singular = Str::snake(class_basename($this->argument('class')));
        $controller = Str::studly(class_basename($this->argument('class')));

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
                    $insertar = '            \''.$column[0].'\' => $this->faker->text($maxNbChars = '.$column[2].'),';
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
                    $insertar = '            \''.$column[0].'\' => $this->faker->regexify(\'[A-Z0-9]{'.$column[2].'}\'),';
                } else if ( in_array($column[0],["username","nombreusuario","nombre_usuario"]) ) {
                    $insertar = '            \''.$column[0].'\' => $this->faker->userName,';
                } else if ( isset($column[2]) ) {
                    if ( isset($column[1]) ) {
                        if ($column[1] == 'string') {
                            if (intval($column[1]) > 4) {
                                $insertar = '            \''.$column[0].'\' => $this->faker->text($maxNbChars = '.$column[2].'),';
                            } else {
                                $insertar = '            \''.$column[0].'\' => $this->faker->regexify(\'[A-Z0-9]{'.$column[2].'}\'),';
                            }
                        } elseif ($column[1] == 'float'){
                            $insertar = '            \''.$column[0].'\' => $this->faker->randomFloat(NULL, 1, '.$column[3].'),';
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


    protected function insertModelCode()
    {
        $dir = "app/Models";

        $insertar = "";

        $newest_model = $this->last_file($dir);

        $ruta_model = $dir . '/' . $newest_model;

        $f=fopen($ruta_model, 'r+');

        $contenido = file_get_contents($ruta_model);

        $split_content = explode('}', $contenido);

        $array_fields = array_reverse($this->argument('fields'));

        $has_many = preg_grep('/^hasMany:.*/', $array_fields);
        $belongs_to = preg_grep('/^belongsTo:.*/', $array_fields);
        $belongs_to_many = preg_grep('/^belongsToMany:.*/', $array_fields);

        $array_fields = array_diff($array_fields, $has_many);
        // $array_fields = array_diff($array_fields, $belongs_to);
        $array_fields = array_diff($array_fields, $belongs_to_many);

        foreach ($array_fields as $field) {
            $only_field = explode(":",$field);
            $insertar .= '"'.$only_field[0].'",';
            if (isset($only_field[1]) && isset($only_field[2]) && $only_field[0] == "belongsTo") {
                $insertar = '"'.$only_field[2].'",';
            }
        }

        $insertar = '    protected $fillable = ['.rtrim($insertar,",").'];';

        foreach($belongs_to as $key => $val) {
            $nombre = explode(":",$val);
            $insertar .= PHP_EOL.PHP_EOL.'    public function '.Str::plural(strtolower($nombre[1])).'() {'.PHP_EOL;
            $insertar .= '        return $this->belongsTo('.$nombre[1].'::class);'.PHP_EOL.'    }';
        }

        //class Driver{public function cars(){return $this->belongsToMany(Car::class);}}
        //class Car{public function drivers(){return $this->belongsToMany(Driver::class);}}

        foreach($belongs_to_many as $key => $val) {
            $nombres = explode("-", explode(":",$val)[1]);
            $insertar .= PHP_EOL.PHP_EOL."    //Insertar en Modelo: ".$nombres[0];
            $insertar .= PHP_EOL.'    public function '.Str::plural(strtolower($nombres[0])).'() {'.PHP_EOL;
            $insertar .= '        return $this->belongsToMany('.$nombres[0].'::class);'.PHP_EOL.'    }';

            $insertar .= PHP_EOL.PHP_EOL."    //Insertar en Modelo: ".$nombres[1];
            $insertar .= PHP_EOL.'    public function '.Str::plural(strtolower($nombres[1])).'() {'.PHP_EOL;
            $insertar .= '        return $this->belongsToMany('.$nombres[1].'::class);'.PHP_EOL.'    }';
        }

        $contenido=$split_content[0].$insertar.PHP_EOL."}";

        fwrite($f, $contenido);
    }



    protected function insertTableCode($paginacion = "25")
    {
        $dir = "app/Tables";

        $insertar = "";

        $newest_table = $this->last_file($dir);

        $ruta_table = $dir . '/' . $newest_table;

        $f=fopen($ruta_table, 'r+');

        $contenido = file_get_contents($ruta_table);

        $split_content = explode("['id'", $contenido);

        $array_fields = array_reverse($this->argument('fields'));

        foreach ($array_fields as $field) {
            $only_field = explode(":",$field);
            $campo_externo = (isset($only_field[1]) && isset($only_field[2]) && isset($only_field[3])) ? $only_field[3] : "id";

            if (isset($only_field[1]) && isset($only_field[2]) && $only_field[0] == "belongsTo") {
                $insertar = '"'.Str::plural(Str::snake(class_basename($only_field[1]))).'.'.$campo_externo.'",';
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

            if (isset($only_field[1]) && isset($only_field[2]) && $only_field[0] == "belongsTo") {
                $insertar .= PHP_EOL.'            ->column(\''.Str::plural(Str::snake(class_basename($only_field[1]))).'.'.$campo_externo.'\', sortable: true)';
            } else {
                $insertar .= PHP_EOL.'            ->column(\''.$only_field[0].'\', sortable: true)';
            }
        }

        $contenido=$split_content[0]."->column('id', sortable: true)".$insertar.PHP_EOL."            ->column(label: 'Actions', exportAs: false)".PHP_EOL."            ->paginate(".$paginacion.");".$split_content[1];

        fwrite($f, $contenido);
    }

    protected function insertRequestCode() {
        $requestName = Str::studly(class_basename($this->argument('class')));

        $dir = "app/Http/Requests";

        $insertar = "";

        $ruta_store_request = $dir . '/Store' . $requestName . 'Request.php';

        $f=fopen($ruta_store_request, 'r+');

        $contenido = file_get_contents($ruta_store_request);

        $split_content = explode("//", $contenido);

        $array_fields = array_reverse($this->argument('fields'));

        foreach ($array_fields as $field) {
            $only_field = explode(":",$field);
            $numerico = ["bigInteger","bigSerial","serial","float4","float8","int","int2","int4","int8","integer","decimal"];
            $tipo_dato = isset($only_field[1])?(in_array($only_field[1],$numerico)?"integer":"string"):"string";
            $longitud_dato = isset($only_field[2])?$only_field[2]:"255";
            if ($only_field[0] != "belongsTo") {
                if ($tipo_dato=="string") {
                    $insertar .= '            \''.$only_field[0].'\' => [\'required\', \''.$tipo_dato.'\', \'max:'.$longitud_dato.'\'],'.PHP_EOL;
                } else {
                    $insertar .= '            \''.$only_field[0].'\' => [\'required\', \''.$tipo_dato.'\'],'.PHP_EOL;
                }
            } else {
                $insertar .= '            \''.$only_field[2].'\' => [\'required\'],'.PHP_EOL;
            }

        }

        $contenido=$split_content[0].PHP_EOL.$insertar.$split_content[1];

        $split_content = explode("use Illuminate\Foundation\Http\FormRequest;", $contenido);
        $insertar = "use Illuminate\Foundation\Http\FormRequest;".PHP_EOL."use Illuminate\Support\Facades\Gate;";
        $contenido=$split_content[0].$insertar.PHP_EOL.$split_content[1];

        $split_content = explode("return false;", $contenido);
        $insertar = "return Gate::allows('".Str::lower($this->argument('class'))."_create');";
        $contenido=$split_content[0].$insertar.PHP_EOL.$split_content[1];

        fwrite($f, $contenido);

        // echo $contenido;

        $insertar = "";

        $ruta_update_request = $dir . '/Update' . $requestName . 'Request.php';

        $f=fopen($ruta_update_request, 'r+');

        $contenido = file_get_contents($ruta_update_request);

        $split_content = explode("//", $contenido);

        $array_fields = array_reverse($this->argument('fields'));

        foreach ($array_fields as $field) {
            $only_field = explode(":",$field);
            $numerico = ["bigInteger","bigSerial","serial","float4","float8","int","int2","int4","int8","integer","decimal"];
            $tipo_dato = isset($only_field[1])?(in_array($only_field[1],$numerico)?"integer":"string"):"string";
            $longitud_dato = isset($only_field[2])?$only_field[2]:"255";
            if ($only_field[0] != "belongsTo") {
                if ($tipo_dato=="string") {
                    $insertar .= '            \''.$only_field[0].'\' => [\'required\', \''.$tipo_dato.'\', \'max:'.$longitud_dato.'\'],'.PHP_EOL;
                } else {
                    $insertar .= '            \''.$only_field[0].'\' => [\'required\', \''.$tipo_dato.'\'],'.PHP_EOL;
                }
            } else {
                $insertar .= '            \''.$only_field[2].'\' => [\'required\'],'.PHP_EOL;
            }
        }

        $contenido=$split_content[0].PHP_EOL.$insertar.$split_content[1];

        $split_content = explode("use Illuminate\Foundation\Http\FormRequest;", $contenido);
        $insertar = "use Illuminate\Foundation\Http\FormRequest;".PHP_EOL."use Illuminate\Support\Facades\Gate;";
        $contenido=$split_content[0].$insertar.PHP_EOL.$split_content[1];

        $split_content = explode("return false;", $contenido);
        $insertar = "return Gate::allows('".Str::lower($this->argument('class'))."_create');";
        $contenido=$split_content[0].$insertar.PHP_EOL.$split_content[1];

        fwrite($f, $contenido);
    }

    function setPermissions() {
        $usuario_admin=User::find(1);
        $clase = Str::snake(class_basename($this->argument('class')));
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
        $ruta_menu = "resources/views/layouts/navigation.blade.php";

        $ruta = Str::snake($this->argument('class'));
        $clase = Str::plural($ruta);
        $titulo = preg_replace('/([a-z])([A-Z])/s','$1 $2', $this->argument('class'));

        $f=fopen($ruta_menu, 'r+');

        $contenido = file_get_contents($ruta_menu);

        $last_endcan = strrpos($contenido,"@endcan");

        $beg = substr($contenido,0,$last_endcan+7);
        $end = substr($contenido,$last_endcan+7,strlen($contenido)-$last_endcan);

        $insertar = "
                        @can('".$ruta."_access')
                            <x-nav-link :href=\"route('".$clase.".index')\" :active=\"request()->routeIs('".$clase.".index')\">
                                {{ __('".$titulo."') }}
                            </x-nav-link>
                        @endcan";

        $contenido=$beg.$insertar.$end;

        $first_responsive_nav = strpos($contenido,"</x-responsive-nav-link>");

        $beg = substr($contenido,0,$first_responsive_nav+24);
        $end = substr($contenido,$first_responsive_nav+24,strlen($contenido)-$first_responsive_nav);

        $insertar = "
                @can('".$ruta."_access')
                    <x-responsive-nav-link :href=\"route('".$clase.".index')\" :active=\"request()->routeIs('".$clase.".index')\">
                        {{ __('".$titulo."') }}
                    </x-responsive-nav-link>
                @endcan";

        $contenido=$beg.$insertar.$end;

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
}
