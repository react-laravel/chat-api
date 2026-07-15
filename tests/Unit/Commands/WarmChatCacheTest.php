<?php

namespace Tests\Unit\Commands;

use App\Console\Commands\Chat\WarmChatCache;
use App\Services\Chat\ChatCacheService;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

class WarmChatCacheTest extends TestCase
{
    use RefreshDatabase;

    private WarmChatCache $command;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a simple mock service for basic tests
        $mockService = new class extends ChatCacheService
        {
            public function warmUpCache(): void
            {
                // Do nothing - simulate successful cache warming
            }

            public function getCacheStats(): array
            {
                return [
                    'driver' => 'redis',
                    'memory_usage' => '1.2MB',
                    'connected_clients' => '5',
                    'keyspace_hits' => '1000',
                    'keyspace_misses' => '50',
                ];
            }
        };

        // Create command instance with mocked service
        $this->command = new WarmChatCache($mockService);

        // Set up output for the command
        $this->command->setOutput(new OutputStyle(
            new ArrayInput([]),
            new NullOutput
        ));
    }

    /**
     * Test successful cache warming
     */
    public function test_handle_warms_cache_successfully(): void
    {
        // Arrange - create a mock that doesn't throw exceptions
        $mockService = new class extends ChatCacheService
        {
            public function warmUpCache(): void
            {
                // Do nothing - simulate successful cache warming
            }

            public function getCacheStats(): array
            {
                return [
                    'driver' => 'redis',
                    'memory_usage' => '1.2MB',
                    'connected_clients' => '5',
                    'keyspace_hits' => '1000',
                    'keyspace_misses' => '50',
                ];
            }
        };

        $command = new WarmChatCache($mockService);

        // Set up output for the command
        $command->setOutput(new OutputStyle(
            new ArrayInput([]),
            new NullOutput
        ));

        // Act
        $result = $command->handle();

        // Assert
        $this->assertEquals(0, $result); // Command::SUCCESS = 0
    }

    /**
     * Test cache warming failure
     */
    public function test_handle_returns_failure_when_cache_service_throws_exception(): void
    {
        // Arrange - create a mock that throws an exception
        $mockService = new class extends ChatCacheService
        {
            public function warmUpCache(): void
            {
                throw new Exception('Cache warming failed');
            }

            public function getCacheStats(): array
            {
                return [];
            }
        };

        $command = new WarmChatCache($mockService);

        // Set up output for the command
        $command->setOutput(new OutputStyle(
            new ArrayInput([]),
            new NullOutput
        ));

        // Act
        $result = $command->handle();

        // Assert
        $this->assertEquals(1, $result); // Command::FAILURE = 1
    }

    /**
     * Test command signature
     */
    public function test_command_has_correct_signature(): void
    {
        // Assert - use reflection to access protected property
        $reflection = new ReflectionClass($this->command);
        $property = $reflection->getProperty('signature');
        $property->setAccessible(true);
        $this->assertEquals('chat:warm-cache', $property->getValue($this->command));
    }

    /**
     * Test command description
     */
    public function test_command_has_correct_description(): void
    {
        // Assert
        $this->assertEquals('预热聊天相关缓存以提升性能', $this->command->getDescription());
    }

    /**
     * Test command constructor injects dependencies
     */
    public function test_constructor_injects_cache_service(): void
    {
        // Arrange & Act
        $mockService = new class extends ChatCacheService
        {
            public function warmUpCache(): void
            {
                // Do nothing
            }

            public function getCacheStats(): array
            {
                return [];
            }
        };
        $command = new WarmChatCache($mockService);

        // Assert - use reflection to access private property
        $reflection = new ReflectionClass($command);
        $property = $reflection->getProperty('cacheService');
        $property->setAccessible(true);
        $this->assertInstanceOf(ChatCacheService::class, $property->getValue($command));
    }

    /**
     * Test command is instantiable
     */
    public function test_command_can_be_instantiated(): void
    {
        // Assert
        $this->assertInstanceOf(WarmChatCache::class, $this->command);
    }

    /**
     * Test command extends base Command class
     */
    public function test_command_extends_base_command(): void
    {
        // Assert
        $this->assertInstanceOf(Command::class, $this->command);
    }

    /**
     * Test command with different exception types
     */
    public function test_handle_handles_different_exception_types(): void
    {
        // Test with RuntimeException
        $mockService = new class extends ChatCacheService
        {
            public function warmUpCache(): void
            {
                throw new \RuntimeException('Runtime error');
            }

            public function getCacheStats(): array
            {
                return [];
            }
        };

        $command = new WarmChatCache($mockService);

        // Set up output for the command
        $command->setOutput(new OutputStyle(
            new ArrayInput([]),
            new NullOutput
        ));

        $result = $command->handle();
        $this->assertEquals(1, $result);

        // Test with InvalidArgumentException
        $mockService = new class extends ChatCacheService
        {
            public function warmUpCache(): void
            {
                throw new \InvalidArgumentException('Invalid argument');
            }

            public function getCacheStats(): array
            {
                return [];
            }
        };

        $command = new WarmChatCache($mockService);

        // Set up output for the command
        $command->setOutput(new OutputStyle(
            new ArrayInput([]),
            new NullOutput
        ));

        $result = $command->handle();
        $this->assertEquals(1, $result);
    }

    /**
     * Test that getCacheStats is called after successful warm up
     */
    public function test_get_cache_stats_is_called_after_successful_warm_up(): void
    {
        // Arrange - create a mock that tracks method calls
        $statsCalled = false;
        $mockService = new class($statsCalled) extends ChatCacheService
        {
            private $statsCalled;

            public function __construct(&$statsCalled)
            {
                $this->statsCalled = &$statsCalled;
            }

            public function warmUpCache(): void
            {
                // Do nothing - simulate successful cache warming
            }

            public function getCacheStats(): array
            {
                $this->statsCalled = true;

                return [
                    'driver' => 'redis',
                    'memory_usage' => '1.2MB',
                    'connected_clients' => '5',
                    'keyspace_hits' => '1000',
                    'keyspace_misses' => '50',
                ];
            }
        };

        $command = new WarmChatCache($mockService);

        // Set up output for the command
        $command->setOutput(new OutputStyle(
            new ArrayInput([]),
            new NullOutput
        ));

        // Act
        $command->handle();

        // Assert
        $this->assertTrue($statsCalled, 'getCacheStats should be called after successful warm up');
    }

    /**
     * Test that getCacheStats is not called when warm up fails
     */
    public function test_get_cache_stats_is_not_called_when_warm_up_fails(): void
    {
        // Arrange - create a mock that tracks method calls
        $statsCalled = false;
        $mockService = new class($statsCalled) extends ChatCacheService
        {
            private $statsCalled;

            public function __construct(&$statsCalled)
            {
                $this->statsCalled = &$statsCalled;
            }

            public function warmUpCache(): void
            {
                throw new Exception('Warm up failed');
            }

            public function getCacheStats(): array
            {
                $this->statsCalled = true;

                return [];
            }
        };

        $command = new WarmChatCache($mockService);

        // Set up output for the command
        $command->setOutput(new OutputStyle(
            new ArrayInput([]),
            new NullOutput
        ));

        // Act
        $command->handle();

        // Assert
        $this->assertFalse($statsCalled, 'getCacheStats should not be called when warm up fails');
    }
}
