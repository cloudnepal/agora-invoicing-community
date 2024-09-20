<?php

namespace App\Http\Controllers\BillingInstaller;

use App\Http\Controllers\Controller;
use App\Http\Controllers\SyncBillingToLatestVersion;
use App\Model\Mailjob\QueueService;
use App\User;
use Artisan;
use Cache;
use Exception;
use Illuminate\Http\Request;
use Session;

class InstallerController extends Controller
{
    /**
     * Post configurationcheck
     * checking prerequisites.
     *
     * @return \Illuminate\Http\JsonResponse view
     */
    public function configurationcheck(Request $request)
    {
        $inputs = $request->only([
            'host', 'databasename', 'username', 'password', 'port',
            'db_ssl_key', 'db_ssl_cert', 'db_ssl_ca', 'db_ssl_verify',
        ]);
        Cache::forever('config-check', 'config-check');
        Session::put(array_merge($inputs, ['default' => 'mysql', 'db_ssl_key' => $inputs['db_ssl_key'] ?? null, 'db_ssl_cert' => $inputs['db_ssl_cert'] ?? null, 'db_ssl_ca' => $inputs['db_ssl_ca'] ?? null, 'db_ssl_verify' => $inputs['db_ssl_verify'] ?? null]));
        Session::put('setup', 1);
        Cache::forever('dummy_data_installation', false);

        return response()->json((new DatabaseSetupController())->testResult());
    }

    /**
     * Get database
     * checking prerequisites.
     *
     * @return type view
     */
    public function database(Request $request)
    {
        // checking if the installation is running for the first time or not
        if (Cache::get('config-check') == 'config-check') {
            return View::make('themes/default1/installer/helpdesk/view4');
        } else {
            return Redirect::route('config');
        }
    }

    /**
     * Get account
     * checking prerequisites.
     *
     * @return type view
     */
    public function account(Request $request)
    {
        return View::make('installer/demo');
    }

    public function checkPreInstall()
    {
        Artisan::call('key:generate', ['--force' => true]);

        $url = url('migrate');
        $result = ['success' => 'Pre migration has been tested successfully', 'next' => 'Migrating tables in database', 'api' => $url];

        return response()->json(compact('result'));
    }

    public function migrate()
    {
        $db_install_method = '';
        try {
            if (Cache::get('databasename') != env('DB_DATABASE')) {
                throw new Exception('Database connection did not update.', 500);
            }
            $tableNames = \Schema::getConnection()->getDoctrineSchemaManager()->listTableNames();
            //allowing migrations table in db as it does not get removed on "migrate:reset"
            $tableNames = array_unique(array_merge(['migrations'], $tableNames));
            if (count($tableNames) === 1) {
                $this->rollBackMigration();
                (new SyncBillingToLatestVersion())->sync();

                if (Cache::get('dummy_data_installation')) {
                    $path = base_path().DIRECTORY_SEPARATOR.'DB'.DIRECTORY_SEPARATOR.'dummy-data.sql';
                    \DB::unprepared(file_get_contents($path));
                }
            }
        } catch (Exception $ex) {
            // $this->rollBackMigration();
            $result = ['error' => $ex->getMessage()];

            return response()->json(compact('result'), 500);
        }

        $message = 'Database has been setup successfully.';
        $result = ['success' => $message];

        return response()->json(compact('result'));
    }

    public function rollBackMigration()
    {
        try {
            Artisan::call('migrate', ['--force' => true]);
//            shell_exec('php ../artisan passport:install');
            // Artisan::call('passport:install', ['--force' => true]);
        } catch (Exception $ex) {
            $result = ['error' => $ex->getMessage()];

            return response()->json(compact('result'), 500);
        }
    }

    public function createEnv($api = true)
    {
        try {
            $default = request()->get('default', Session::get('default'));
            $host = request()->get('host', Session::get('host'));
            $database = request()->get('databasename', Session::get('databasename'));
            $dbusername = request()->get('username', Session::get('username'));
            $dbpassword = request()->get('password', Session::get('password'));
            $port = request()->get('port', Session::get('port'));
            $sslKey = request()->get('db_ssl_key', Session::get('db_ssl_key'));
            $sslCert = request()->get('db_ssl_cert', Session::get('db_ssl_cert'));
            $sslCa = request()->get('db_ssl_ca', Session::get('db_ssl_ca'));
            $sslVerify = request()->get('db_ssl_verify', Session::get('db_ssl_verify'));

            $this->env($default, $host, $port, $database, $dbusername, $dbpassword, null, $sslKey, $sslCert, $sslCa, $sslVerify);
        } catch (Exception $ex) {
            return response()->json(['result' => $ex->getMessage()], 500);
        }

        if ($api) {
            Cache::forever('databasename', $database);
            $url = url('preinstall/check');
            $result = [
                'success' => 'Environment configuration file has been created successfully',
                'next' => 'Running pre-migration test',
                'api' => $url,
            ];

            return response()->json(compact('result'));
        }
    }

    public function env($default, $host, $port, $database, $dbusername, $dbpassword, $appUrl = null)
    {
        $ENV = [
            'APP_NAME' => 'Faveo:'.md5(uniqid()),
            'APP_DEBUG' => 'false',
            'APP_BUGSNAG' => 'true',
            'APP_URL' => $appUrl ?? url('/'), // for CLI installation
            'APP_KEY' => 'base64:h3KjrHeVxyE+j6c8whTAs2YI+7goylGZ/e2vElgXT6I=',
            'DB_TYPE' => $default,
            'DB_HOST' => "\"$host\"",
            'DB_PORT' => "\"$port\"",
            'DB_INSTALL' => 0,
            'DB_DATABASE' => "\"$database\"",
            'DB_USERNAME' => "\"$dbusername\"",
            'DB_PASSWORD' => '"'.str_replace('"', '\"', $dbpassword).'"',
            'DB_ENGINE' => 'InnoDB', // Update after resolving InnoDB issues
            'CACHE_DRIVER' => 'file',
            'SESSION_DRIVER' => 'file',
            'SESSION_COOKIE_NAME' => 'faveo_'.rand(0, 10000),
            'QUEUE_DRIVER' => 'sync',
            'FCM_SERVER_KEY' => 'AIzaSyBJNRvyub-_-DnOAiIJfuNOYMnffO2sfw4',
            'FCM_SENDER_ID' => '505298756081',
            'PROBE_PASS_PHRASE' => md5(uniqid()),
            'REDIS_DATABASE' => '0',
            'BROADCAST_DRIVER' => 'pusher',
            'LARAVEL_WEBSOCKETS_ENABLED' => 'false',
            'LARAVEL_WEBSOCKETS_PORT' => 6001,
            'LARAVEL_WEBSOCKETS_HOST' => '127.0.0.1',
            'LARAVEL_WEBSOCKETS_SCHEME' => 'http',
            'PUSHER_APP_ID' => str_random(16),
            'PUSHER_APP_KEY' => md5(uniqid()),
            'PUSHER_APP_SECRET' => md5(uniqid()),
            'PUSHER_APP_CLUSTER' => 'mt1',
            'MIX_PUSHER_APP_KEY' => '"${PUSHER_APP_KEY}"',
            'MIX_PUSHER_APP_CLUSTER' => '"${PUSHER_APP_CLUSTER}"',
            'SOCKET_CLIENT_SSL_ENFORCEMENT' => 'false',
            'LARAVEL_WEBSOCKETS_SSL_LOCAL_CERT' => 'null',
            'LARAVEL_WEBSOCKETS_SSL_LOCAL_PK' => 'null',
            'LARAVEL_WEBSOCKETS_SSL_PASSPHRASE' => 'null',
            'SESSION_SECURE_COOKIE' => 'false',
            'CSRF_COOKIE_HTTP_ONLY' => 'false',
            'RECAPTCHA_SITE_KEY' => '',
            'VITE_RECAPTCHA_SITE_KEY' => '"${RECAPTCHA_SITE_KEY}"',
        ];

        $config = collect($ENV)
            ->map(fn ($val, $key) => "$key=$val")
            ->implode("\n");

        $envPath = base_path('.env');
        $exampleEnvPath = base_path('example.env');

        // Remove old .env file if it exists
        if (is_file($envPath)) {
            unlink($envPath);
        }

        // Create a new example.env file if it doesn't exist
        if (! is_file($exampleEnvPath)) {
            touch($exampleEnvPath);
        }

        // Write new environment configuration to example.env
        file_put_contents($exampleEnvPath, $config);

        // Rename example.env to .env
        rename($exampleEnvPath, $envPath);
    }

    public function updateInstallEnv(string $environment, string $driver, $redisConfig = [])
    {
        $env = base_path().DIRECTORY_SEPARATOR.'.env';
        if (! is_file($env)) {
            return errorResponse('.env not found', 400);
        }

        $txt1 = "\nAPP_ENV=$environment";
        file_put_contents($env, str_replace('DB_INSTALL='. 0, 'DB_INSTALL='. 1, file_get_contents($env)));
        file_put_contents($env, $txt1.PHP_EOL, FILE_APPEND | LOCK_EX);

        foreach ($redisConfig as $key => $value) {
            $line = strtoupper($key).'='.$value.PHP_EOL;
            file_put_contents($env, $line, FILE_APPEND | LOCK_EX);
        }

        // If Redis is used as cache driver, update .env and relevant database records
        if ($driver == 'redis') {
            // Update .env file to set CACHE_DRIVER to 'redis'
            file_put_contents($env, str_replace('CACHE_DRIVER='.getenv('CACHE_DRIVER'), 'CACHE_DRIVER='.'redis', file_get_contents($env)));

            // Disable all active QueueServices
            QueueService::where('status', 1)->update(['status' => 0]);

            // Enable the Redis QueueService
            $queue = QueueService::where('short_name', 'redis')->first();
            $queue->status = 1;
            $queue->save();

            // Update or create extra field relations for the QueueService
            $queue->extraFieldRelation()->updateOrCreate(['key' => 'driver'], ['key' => 'driver', 'value' => 'redis']);
            $queue->extraFieldRelation()->updateOrCreate(['key' => 'queue'], ['key' => 'queue', 'value' => 'default']);
        }
    }

    /**
     * Post accountcheck
     * checking prerequisites.
     *
     * @param type InstallerRequest $request
     * @return type view
     */
    public function accountcheck(Request $request)
    {
        // Validation rules and custom messages
        $validator = \Validator::make($request->all(), [
            'first_name' => 'required|string|max:20',
            'last_name' => 'required|string|max:20',
            'user_name' => 'required|string|max:30|unique:users,user_name',
            'email' => 'required|string|max:50|email|unique:users,email',
            'password' => [
                'required',
                'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*\W).{8,}$/',
            ],
            'driver' => 'nullable|string|in:redis',
            'redis_host' => 'nullable|required_if:driver,redis|string',
            'redis_password' => 'nullable|required_if:driver,redis|string',
            'redis_port' => 'nullable|required_if:driver,redis|numeric',
            'environment' => 'required|string',
            'cache_driver' => 'required|string',
        ], [
            'password.regex' => 'Password must contain at least 8 characters, one uppercase letter, one lowercase letter, one number, and one special character.',
            'redis_host.required_if' => 'Redis host is required.',
            'redis_password.required_if' => 'Redis password is required.',
            'redis_port.required_if' => 'Redis port is required.',
        ]);

        // Return validation errors if any
        if ($validator->fails()) {
            return errorResponse($validator->errors()->first(), 400);
        }

        try {
            // Create the user
            $user = User::create([
                'first_name' => $request->input('first_name'),
                'last_name' => $request->input('last_name'),
                'user_name' => $request->input('user_name'),
                'email' => $request->input('email'),
                'password' => \Hash::make($request->input('password')),
                'active' => 1,
                'role' => 'admin',
                'mobile_verified' => 1,
            ]);
            // Redis configuration based on environment
            if ($request->input('cache_driver') === 'redis') {
                $redisConfig = array_filter([
                    'redis_host' => $request->input('redis_host'),
                    'redis_password' => $request->input('redis_password'),
                    'redis_port' => $request->input('redis_port'),
                ]);

                $this->updateInstallEnv($request->input('environment'), $request->input('cache_driver'), $redisConfig);
            } else {
                $this->updateInstallEnv($request->input('environment'), $request->input('cache_driver'));
            }

            // Cache 'getting-started' status
            Cache::forever('getting-started', 'getting-started');

            // Return success response
            return successResponse('Setup completed successfully!', 201);
        } catch (\Exception $e) {
            // Return error response in case of exception
            return errorResponse($e->getMessage(), 400);
        }
    }

    public function getTimeZoneDropDown()
    {
        $timezonesList = \App\Model\Common\Timezone::get();
        foreach ($timezonesList as $timezone) {
            $location = $timezone->location;
            if ($location) {
                $start = strpos($location, '(');
                $end = strpos($location, ')', $start + 1);
                $length = $end - $start;
                $result = substr($location, $start + 1, $length - 1);
                $display[] = ['id' => $timezone->id, 'name' => '('.$result.')'.' '.$timezone->name];
            }
        }

        return $display;
    }
}
