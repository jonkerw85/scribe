<?php

namespace Knuckles\Scribe\Tests\GenerateDocumentation;

use Illuminate\Support\Facades\File as FileFacade;
use Illuminate\Support\Facades\Route as RouteFacade;
use Knuckles\Scribe\Commands\GenerateDocumentation;
use Knuckles\Scribe\Scribe;
use Knuckles\Scribe\Tests\BaseLaravelTest;
use Knuckles\Scribe\Tests\Fixtures\TestController;
use Knuckles\Scribe\Tests\Fixtures\TestGroupController;
use Knuckles\Scribe\Tests\Fixtures\TestIgnoreThisController;
use Knuckles\Scribe\Tests\Fixtures\TestPartialResourceController;
use Knuckles\Scribe\Tests\Fixtures\TestResourceController;
use Knuckles\Scribe\Tests\Fixtures\TestUser;
use Knuckles\Scribe\Tests\TestHelpers;
use Knuckles\Scribe\Tools\Utils;

class BehavioursTest extends BaseLaravelTest
{
    use TestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $factory = app(\Illuminate\Database\Eloquent\Factory::class);
        $factory->define(TestUser::class, function () {
            return [
                'id' => 4,
                'first_name' => 'Tested',
                'last_name' => 'Again',
                'email' => 'a@b.com',
            ];
        });
    }

    public function tearDown(): void
    {
        Utils::deleteDirectoryAndContents('public/docs');
        Utils::deleteDirectoryAndContents('.scribe');
    }

    /** @test */
    public function can_process_traditional_laravel_route_syntax_and_callable_tuple_syntax()
    {
        dump(config('scribe'));
        RouteFacade::get('/api/test', [TestController::class, 'withEndpointDescription']);
        RouteFacade::get('/api/array/test', [TestController::class, 'withEndpointDescription']);

        $this->generateAndExpectConsoleOutput(expected: [
            'Processed route: [GET] api/test',
            'Processed route: [GET] api/array/test'
        ]);
    }

    /** @test */
    public function processes_head_routes_as_head_not_get()
    {
        RouteFacade::addRoute('HEAD', '/api/test', [TestController::class, 'withEndpointDescription']);
        $this->generateAndExpectConsoleOutput(expected: ['Processed route: [HEAD] api/test']);
    }

    /**
     * @test
     * @see https://github.com/knuckleswtf/scribe/issues/53
     */
    public function can_process_closure_routes()
    {
        RouteFacade::get('/api/closure', fn() => 'hi');
        $this->generateAndExpectConsoleOutput(expected: ['Processed route: [GET] api/closure']);
    }

    /** @test */
    public function calls_afterGenerating_hook()
    {
        $paths = [];
        Scribe::afterGenerating(function (array $outputPaths) use (&$paths) {
            $paths = $outputPaths;
        });
        RouteFacade::get('/api/test', [TestController::class, 'withEndpointDescription']);

        $this->generate();

        $this->assertEquals([
            'html' => realpath('public/docs/index.html'),
            'blade' => null,
            'postman' => realpath('public/docs/collection.json') ?: null,
            'openapi' => realpath('public/docs/openapi.yaml') ?: null,
            'assets' => [
                'js' => realpath('public/docs/js'),
                'css' => realpath('public/docs/css'),
                'images' => realpath('public/docs/images'),
            ],
        ], $paths);

        Scribe::afterGenerating(fn() => null);
    }

    /** @test */
    public function calls_bootstrap_hook()
    {
        $commandInstance = null;

        Scribe::bootstrap(function (GenerateDocumentation $command) use (&$commandInstance) {
            $commandInstance = $command;
        });

        RouteFacade::get('/api/test', [TestController::class, 'withEndpointDescription']);

        $this->generate();

        $this->assertTrue($commandInstance instanceof GenerateDocumentation);

        Scribe::bootstrap(fn() => null);
    }

    /** @test */
    public function skips_methods_and_classes_with_hidefromapidocumentation_tag()
    {
        RouteFacade::get('/api/skip', [TestController::class, 'skip']);
        RouteFacade::get('/api/skipClass', TestIgnoreThisController::class . '@dummy');
        RouteFacade::get('/api/test', [TestController::class, 'withEndpointDescription']);

        $this->generateAndExpectConsoleOutput(expected: [
            'Skipping route: [GET] api/skip',
            'Skipping route: [GET] api/skipClass',
            'Processed route: [GET] api/test'
        ]);
    }

    /** @test */
    public function warns_of_nonexistent_response_files()
    {
        RouteFacade::get('/api/non-existent', [TestController::class, 'withNonExistentResponseFile']);
        $this->generateAndExpectConsoleOutput(expected: ['@responseFile i-do-not-exist.json does not exist']);
    }

    /** @test */
    public function can_parse_resource_routes()
    {
        RouteFacade::resource('/api/users', TestResourceController::class)->only(['index', 'store']);

        $this->generateAndExpectConsoleOutput(
            expected: [
                'Processed route: [GET] api/users',
                'Processed route: [POST] api/users'
            ],
            notExpected: [
                'Processed route: [PUT,PATCH] api/users/{user}',
                'Processed route: [DELETE] api/users/{user}',]
        );
    }

    /** @test */
    public function supports_partial_resource_controller()
    {
        RouteFacade::resource('/api/users', TestPartialResourceController::class);

        $this->generateAndExpectConsoleOutput(expected: [
            'Processed route: [GET] api/users',
            'Processed route: [PUT,PATCH] api/users/{user}'
        ]);
    }

    /** @test */
    public function can_customise_static_output_path()
    {
        RouteFacade::get('/api/action1', TestGroupController::class . '@action1');

        $this->setConfig(['static.output_path' => 'static/docs']);
        $this->assertFileDoesNotExist('static/docs/index.html');

        $this->generate();

        $this->assertFileExists('static/docs/index.html');

        Utils::deleteDirectoryAndContents('static/');
    }

    /** @test */
    public function can_generate_with_apiresource_tag_but_without_apiresourcemodel_tag()
    {
        RouteFacade::get('/api/test', [TestController::class, 'withEmptyApiResource']);
        $this->generateAndExpectConsoleOutput(expected: [
            "Couldn't detect an Eloquent API resource model",
            'Processed route: [GET] api/test'
        ]);
    }
}
