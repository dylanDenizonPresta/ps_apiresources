<?php
/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License version 3.0
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

declare(strict_types=1);

namespace PsApiResourcesTest\Integration\ApiPlatform;

use Module;
use Tests\Resources\DatabaseDump;

class ModuleEndpointTest extends ApiTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        DatabaseDump::restoreMatchingTables('/module/');
        self::createApiClient(['module_write', 'module_read']);
    }

    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();
        DatabaseDump::restoreMatchingTables('/module/');
    }

    public function getProtectedEndpoints(): iterable
    {
        yield 'get endpoint' => [
            'GET',
            '/module/ps_featureproducts',
        ];

        yield 'list modules' => [
            'GET',
            '/modules',
        ];

        yield 'bulk toggle status' => [
            'PUT',
            '/modules/toggle-status',
        ];

        yield 'toggle module status' => [
            'PUT',
            '/module/{technicalName}/status',
        ];

        yield 'reset module' => [
            'PATCH',
            '/module/{technicalName}/reset',
        ];
    }

    public function testModuleNotFound(): void
    {
        $bearerToken = $this->getBearerToken(['module_read', 'module_write']);
        // GET on non existent module returns a 404
        static::createClient()->request('GET', '/module/ps_falsemodule', [
            'auth_bearer' => $bearerToken,
        ]);
        self::assertResponseStatusCodeSame(404);

        // PUT status on non existent module returns a 404
        static::createClient()->request('PUT', '/module/ps_falsemodule/status', [
            'auth_bearer' => $bearerToken,
            'json' => [
                'enabled' => true,
            ],
        ]);
        self::assertResponseStatusCodeSame(404);

        // PATCH reset on non existent module returns a 404
        static::createClient()->request('PATCH', '/module/ps_falsemodule/reset', [
            'auth_bearer' => $bearerToken,
            'json' => [
                'keepData' => true,
            ],
        ]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testListModules(): array
    {
        $modules = $this->listItems('/modules', ['module_read']);
        $this->assertGreaterThan(1, $modules['totalItems']);

        $modules = $this->listItems('/modules', ['module_read'], ['technicalName' => 'ps_apiresources']);
        $this->assertEquals(1, $modules['totalItems']);
        $apiModule = $modules['items'][0];
        $this->assertEquals('ps_apiresources', $apiModule['technicalName']);
        $this->assertTrue($apiModule['enabled']);
        $this->assertTrue(version_compare($apiModule['version'], '0.1.0', '>='));
        $this->assertGreaterThan(0, $apiModule['moduleId']);

        return ['moduleId' => $apiModule['moduleId'], 'technicalName' => $apiModule['technicalName'], 'version' => $apiModule['version']];
    }

    /**
     * @depends testListModules
     */
    public function testGetModuleInfos(array $module): array
    {
        $moduleInfos = $this->getModuleInfos($module['technicalName']);
        $this->assertEquals(
            [
                'moduleId' => $module['moduleId'],
                'technicalName' => $module['technicalName'],
                'version' => $module['version'],
                'enabled' => true,
                'installed' => true,
            ],
            $moduleInfos
        );

        return $module;
    }

    /**
     * @depends testGetModuleInfos
     */
    public function testBulkUpdateStatus(array $module): array
    {
        // Check number of disabled modules
        $disabledModules = $this->listItems('/modules', ['module_read'], ['enabled' => false]);
        $this->assertEquals(0, $disabledModules['totalItems']);

        // Bulk disable on one module
        $bearerToken = $this->getBearerToken(['module_read', 'module_write']);
        static::createClient()->request('PUT', '/modules/toggle-status', [
            'auth_bearer' => $bearerToken,
            'json' => [
                'modules' => [
                    $module['technicalName'],
                ],
                'enabled' => false,
            ],
        ]);
        self::assertResponseStatusCodeSame(204);

        // Check updated disabled status
        $moduleInfos = $this->getModuleInfos($module['technicalName']);
        $this->assertFalse($moduleInfos['enabled']);

        // Check number of disabled modules
        $disabledModules = $this->listItems('/modules', ['module_read'], ['enabled' => false]);
        $this->assertEquals(1, $disabledModules['totalItems']);

        // Bulk enable on one module
        static::createClient()->request('PUT', '/modules/toggle-status', [
            'auth_bearer' => $bearerToken,
            'json' => [
                'modules' => [
                    $module['technicalName'],
                ],
                'enabled' => true,
            ],
        ]);
        self::assertResponseStatusCodeSame(204);

        // Check updated enabled status
        $moduleInfos = $this->getModuleInfos($module['technicalName']);
        $this->assertTrue($moduleInfos['enabled']);

        // Check number of disabled modules
        $disabledModules = $this->listItems('/modules', ['module_read'], ['enabled' => false]);
        $this->assertEquals(0, $disabledModules['totalItems']);

        return $module;
    }

    /**
     * @depends testBulkUpdateStatus
     */
    public function testUpdateModuleStatusDisable(array $module): array
    {
        // Check number of disabled modules
        $disabledModules = $this->listItems('/modules', ['module_read'], ['enabled' => false]);
        $this->assertEquals(0, $disabledModules['totalItems']);

        // Disable specific module
        $bearerToken = $this->getBearerToken(['module_read', 'module_write']);
        $response = static::createClient()->request('PUT', sprintf('/module/%s/status', $module['technicalName']), [
            'auth_bearer' => $bearerToken,
            'json' => [
                'enabled' => false,
            ],
        ]);
        self::assertResponseStatusCodeSame(200);
        $decodedResponse = json_decode($response->getContent(), true);
        $this->assertNotFalse($decodedResponse);

        // Check response from status update request
        $expectedModuleInfos = [
            'moduleId' => $module['moduleId'],
            'technicalName' => $module['technicalName'],
            'version' => $module['version'],
            'enabled' => false,
            'installed' => true,
        ];
        $this->assertEquals($expectedModuleInfos, $decodedResponse);

        // Check updated disabled status
        $moduleInfos = $this->getModuleInfos($module['technicalName']);
        $this->assertEquals($expectedModuleInfos, $moduleInfos);

        // Check number of disabled modules
        $disabledModules = $this->listItems('/modules', ['module_read'], ['enabled' => false]);
        $this->assertEquals(1, $disabledModules['totalItems']);

        // Enable specific module
        $bearerToken = $this->getBearerToken(['module_read', 'module_write']);
        $response = static::createClient()->request('PUT', sprintf('/module/%s/status', $module['technicalName']), [
            'auth_bearer' => $bearerToken,
            'json' => [
                'enabled' => true,
            ],
        ]);
        self::assertResponseStatusCodeSame(200);
        $decodedResponse = json_decode($response->getContent(), true);
        $this->assertNotFalse($decodedResponse);

        // Check response from status update request
        $expectedModuleInfos['enabled'] = true;
        $this->assertEquals($expectedModuleInfos, $decodedResponse);

        // Check updated enabled status
        $moduleInfos = $this->getModuleInfos($module['technicalName']);
        $this->assertTrue($moduleInfos['enabled']);

        // Check updated enabled status
        $moduleInfos = $this->getModuleInfos($module['technicalName']);
        $this->assertEquals($expectedModuleInfos, $moduleInfos);

        // Check number of disabled modules
        $disabledModules = $this->listItems('/modules', ['module_read'], ['enabled' => false]);
        $this->assertEquals(0, $disabledModules['totalItems']);

        return $module;
    }

    /**
     * @depends testBulkUpdateStatus
     */
    public function testResetModule(array $module): void
    {
        // Reset specific module
        $bearerToken = $this->getBearerToken(['module_read', 'module_write']);
        $response = static::createClient()->request('PATCH', sprintf('/module/%s/reset', $module['technicalName']), [
            'auth_bearer' => $bearerToken,
            'json' => [
                'keepData' => false,
            ],
        ]);
        self::assertResponseStatusCodeSame(200);
        $decodedResponse = json_decode($response->getContent(), true);
        $this->assertNotFalse($decodedResponse);

        // Module ID has been modified because the module was uninstalled the reinstalled
        $this->assertNotEquals($module['moduleId'], $decodedResponse['moduleId']);
        $moduleInfos = $this->getModuleInfos($module['technicalName']);
        $this->assertNotEquals($module['moduleId'], $moduleInfos['moduleId']);
        $module['moduleId'] = $decodedResponse['moduleId'];

        // Check response from status update request
        $expectedModuleInfos = [
            'moduleId' => $module['moduleId'],
            'technicalName' => $module['technicalName'],
            'version' => $module['version'],
            'enabled' => true,
            'installed' => true,
        ];
        $this->assertEquals($expectedModuleInfos, $decodedResponse);
    }

    /**
     * @depends testUpdateModuleStatusDisable
     */
    public function restResetModuleNotActive(array $module): void
    {
        $bearerToken = $this->getBearerToken(['module_read', 'module_write']);
        static::createClient()->request('PATCH', sprintf('/module/%s/reset', $module['technicalName']), [
            'auth_bearer' => $bearerToken,
        ]);
        self::assertResponseStatusCodeSame(400);
    }

    private function getModuleInfos(string $technicalName): array
    {
        $bearerToken = $this->getBearerToken(['module_read']);
        $response = static::createClient()->request('GET', '/module/' . $technicalName, [
            'auth_bearer' => $bearerToken,
        ]);
        self::assertResponseStatusCodeSame(200);

        $decodedResponse = json_decode($response->getContent(), true);
        $this->assertNotFalse($decodedResponse);

        return $decodedResponse;
    }
}
