<?php

namespace app\commands;

use app\models\SearchApiPrimitive;
use app\models\SearchApiType;
use Yii;
use yii\apidoc\models\ConstDoc;
use yii\apidoc\models\Context;
use yii\apidoc\models\EventDoc;
use yii\apidoc\models\MethodDoc;
use yii\apidoc\models\PropertyDoc;
use yii\base\ErrorHandler;
use yii\helpers\Console;
use app\apidoc\ApiRenderer;
use yii\helpers\FileHelper;
use yii\helpers\Json;

/**
 * Generates API documentation for Yii.
 */
class ApiController extends \yii\apidoc\commands\ApiController
{
    public $defaultAction = 'generate';
    public $guidePrefix = '';
    protected $version = '2.0';

    /**
     * Generates the API documentation for the specified version of Yii.
     * @param string $version version number, such as 1.1, 2.0
     * @return integer exit status
     */
    public function actionGenerate($version)
    {
        $versions = Yii::$app->params['versions']['api'];
        if (!in_array($version, $versions)) {
            $this->stderr("Unknown version $version. Valid versions are " . implode(', ', $versions) . "\n\n", Console::FG_RED);
            return 1;
        }
        $this->version = $version;

        $targetPath = Yii::getAlias('@app/data');
        $sourcePath = Yii::getAlias('@app/data');

        if ($version[0] === '2') {
            $source = [
                "$sourcePath/yii-$version/framework",
//                "$sourcePath/yii-$version/extensions",
            ];
            $target = "$targetPath/api-$version";
            $this->guide = Yii::$app->params['guide.baseUrl'] . "/{$this->version}/en";

            $this->stdout("Start generating API $version...\n");
            $this->template = 'bootstrap';
            $this->actionIndex($source, $target);

            $this->stdout("Start generating API $version JSON Info...\n");
            $this->template = 'json';
            $this->actionIndex($source, $target);
            $this->splitJson($target);

            $this->stdout("Finished API $version.\n\n", Console::FG_GREEN);
        } elseif ($version[0] === '1') {
            $source = [
                "$sourcePath/yii-$version/framework",
            ];
            $target = "$targetPath/api-$version";
            $cmd = Yii::getAlias("@app/data/yii-$version/build/build");

            if ($version === '1.1' && !is_file($composerYii1 = Yii::getAlias('@app/data/yii-1.1/vendor/autoload.php'))) {
                $this->stdout("WARNING: Composer dependencies of Yii 1.1 are not installed, api generation may fail.\n", Console::BOLD, Console::FG_YELLOW);
            }

            $this->stdout("Start generating API $version...\n");
            FileHelper::createDirectory($target);
            passthru("php $cmd api $target online");

            foreach(FileHelper::findFiles($target, ['only' => ['*.html']]) as $file) {
                file_put_contents($file, preg_replace(
                    '~href="/doc/api/([\w\#\-\.]*)"~i',
                    'href="' . Yii::$app->params['api.baseUrl'] . '/' . $version . '/\1"',
                    file_get_contents($file))
                );
            }
            file_put_contents("$target/api/index.html", str_replace('<h1>Class Reference</h1>', '<h1>Yii Framework ' . $version . ' API Documentation</h1>', file_get_contents("$target/api/index.html")));

            if (!$this->populateElasticsearch1x($source, $target)) {
                return 1;
            }

            $this->stdout("Finished API $version.\n\n", Console::FG_GREEN);
        }

        return 0;
    }

    protected function findRenderer($template)
    {
        if ($template === 'json') {
            return new \yii\apidoc\templates\json\ApiRenderer();
        }
        return new ApiRenderer([
            'version' => $this->version,
        ]);
    }

    public function actionDropElasticsearchIndex()
    {
        echo "currently not implemented\n";
// TODO adjust this
//        if ($this->confirm('really drop the whole elasticsearch index? You need to rebuild it afterwards!')) {
//            SearchApiType::getDb()->createCommand()->deleteIndex(SearchApiType::index());
//            sleep(1);
//            SearchApiType::setMappings();
//            SearchApiPrimitive::setMappings();
//            return 0;
//        }
        return 1;
    }

    protected function populateElasticsearch1x($source, $target)
    {
        // search for files to process
        if (($files = $this->searchFiles($source)) === false) {
            return false;
        }

        // load context from cache
        $context = $this->loadContext($target);
        $this->stdout('Checking for updated files... ');
        foreach ($context->files as $file => $sha) {
            if (!file_exists($file)) {
                $this->stdout('At least one file has been removed. Rebuilding the context...');
                $context = new Context();
                if (($files = $this->searchFiles($source)) === false) {
                    return false;
                }
                break;
            }
            if (sha1_file($file) === $sha) {
                unset($files[$file]);
            }
        }
        $this->stdout('done.' . PHP_EOL, Console::FG_GREEN);

        // process files
        $fileCount = count($files);
        $this->stdout($fileCount . ' file' . ($fileCount == 1 ? '' : 's') . ' to update.' . PHP_EOL);
        Console::startProgress(0, $fileCount, 'Processing files... ', false);
        $done = 0;
        foreach ($files as $file) {
            if (file_exists("$target/api/" . basename($file, '.php') . '.html')) {
                $context->addFile($file);
            }
            Console::updateProgress(++$done, $fileCount);
        }
        Console::endProgress(true);
        $this->stdout('done.' . PHP_EOL, Console::FG_GREEN);

        // save processed data to cache
        $this->storeContext($context, $target);

        $this->updateContext($context);

        $types = array_merge($context->classes, $context->interfaces, $context->traits);

        try {
            Console::startProgress(0, $count = count($types), 'populating elasticsearch index...', false);
            $version = $this->version;
            // first delete all records for this version
            SearchApiType::setMappings();
            SearchApiPrimitive::setMappings();
//        ApiPrimitive::deleteAllForVersion($version);
//        SearchApiType::deleteAllForVersion($version);
            sleep(1);
            $i = 0;
            foreach ($types as $type) {
                SearchApiType::createRecord($type, $version);
                Console::updateProgress(++$i, $count);
            }
            Console::endProgress(true, true);
            $this->stdout("done.\n", Console::FG_GREEN);
        } catch (\Exception $e) {
            if (YII_DEBUG) {
                $this->stdout("!!! FAILED !!! Search will not be available.\n", Console::FG_RED, Console::BOLD);
                $this->stdout(((string) $e) . "\n\n");
            } else {
                throw $e;
            }
        }

        $this->writeJsonFiles1x($target, $types);

        return true;
    }

    public function splitJson($target)
    {
        $json = file_get_contents("$target/types.json");
        FileHelper::createDirectory("$target/json");

        $types = Json::decode($json);

        // write types file:
        file_put_contents("$target/json/typeNames.json", Json::encode(
            array_values(array_map(function($type) {
                return [
                    'name' => $type['name'],
                    'description' => isset($type['shortDescription']) ? $type['shortDescription'] : '',
                ];
            }, $types))
        ));

        // write class-member file:
        $members = [];
        foreach($types as $type) {

            $methods = isset($type['methods']) ? array_map(function($m) { $m['type'] = 'method'; return $m; }, $type['methods']) : [];
            $properties = isset($type['properties']) ? array_map(function($m) { $m['type'] = 'property'; return $m; }, $type['properties']) : [];
            $constants = isset($type['constants']) ? array_map(function($m) { $m['type'] = 'constant'; return $m; }, $type['constants']) : [];
            $events = isset($type['events']) ? array_map(function($m) { $m['type'] = 'event'; return $m; }, $type['events']) : [];

            foreach(array_merge($methods, $properties, $constants, $events) as $method) {

                if ($method['definedBy'] != $type['name']) {
                    continue;
                }

                $k = $method['type'].$method['name'];
                if (!isset($members[$k])) {
                    $members[$k] = [
                        'type' => $method['type'],
                        'name' => $method['name'],
                        'implemented' => [],
                    ];
                }
                $members[$k]['implemented'][] = $type['name'];
            }
        }
        file_put_contents("$target/json/typeMembers.json", Json::encode(array_values($members)));
    }

    public function writeJsonFiles1x($target, $types)
    {
        FileHelper::createDirectory("$target/json");

        // write types file:
        file_put_contents("$target/json/typeNames.json", Json::encode(
            array_values(array_map(function($type) {
                return [
                    'name' => $type->name,
                    'description' => $type->shortDescription,
                ];
            }, $types))
        ));

        // write class-member file:
        $members = [];
        foreach($types as $type) {

            $methods = isset($type->methods) ? $type->methods : [];
            $properties = isset($type->properties) ? $type->properties : [];
            $constants = isset($type->constants) ? $type->constants : [];
            $events = isset($type->events) ? $type->events : [];

            foreach(array_merge($methods, $properties, $constants, $events) as $method) {

                if ($method->definedBy != $type->name) {
                    continue;
                }

                if ($method instanceof MethodDoc) {
                    $mtype = 'method';
                }
                if ($method instanceof PropertyDoc) {
                    $mtype = 'property';
                }
                if ($method instanceof ConstDoc) {
                    $mtype = 'const';
                }
                if ($method instanceof EventDoc) {
                    $mtype = 'event';
                }

                $k = $mtype . $method->name;
                if (!isset($members[$k])) {
                    $members[$k] = [
                        'type' => $mtype,
                        'name' => $method->name,
                        'implemented' => [],
                    ];
                }
                $members[$k]['implemented'][] = $type->name;
            }
        }
        file_put_contents("$target/json/typeMembers.json", Json::encode(array_values($members)));
    }
}
