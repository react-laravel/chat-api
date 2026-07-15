<?php

namespace Tests\Unit\Commands;

use App\Console\Commands\Chat\ClearChatCache;
use App\Services\Chat\ChatCacheService;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

class ClearChatCacheTest extends TestCase
{
    use RefreshDatabase;

    private ClearChatCache $command;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a simple mock service for basic tests
        $mockService = new class extends ChatCacheService
        {
            public function clearAllCache(): void
            {
                // Do nothing - simulate successful cache clearing
            }
        };

        // Create command instance with mocked service
        $this->command = new ClearChatCache($mockService);

        // Set up output for the command
        $this->command->setOutput(new OutputStyle(
            new ArrayInput([]),
            new NullOutput
        ));
    }

    /**
     * Test successful cache clearing
     */
    public function test_handle_clears_cache_successfully(): void
    {
        // Arrange - create a simple mock that doesn't throw exceptions
        $mockService = new class extends ChatCacheService
        {
            public function clearAllCache(): void
            {
                // Do nothing - simulate successful cache clearing
            }
        };

        $command = new ClearChatCache($mockService);

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
     * Test cache clearing failure
     */
    public function test_handle_returns_failure_when_cache_service_throws_exception(): void
    {
        // Arrange - create a mock that throws an exception
        $mockService = new class extends ChatCacheService
        {
            public function clearAllCache(): void
            {
                throw new Exception('Cache connection failed');
            }
        };

        $command = new ClearChatCache($mockService);

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
        $this->assertEquals('chat:clear-cache', $property->getValue($this->command));
    }

    /**
     * Test command description
     */
    public function test_command_has_correct_description(): void
    {
        // Assert
        $this->assertEquals('清除所有聊天室相关的缓存', $this->command->getDescription());
    }

    /**
     * Test command constructor injects dependencies
     */
    public function test_constructor_injects_cache_service(): void
    {
        // Arrange & Act
        $mockService = new class extends ChatCacheService
        {
            public function clearAllCache(): void
            {
                // Do nothing
            }
        };
        $command = new ClearChatCache($mockService);

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
        $this->assertInstanceOf(ClearChatCache::class, $this->command);
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
            public function clearAllCache(): void
            {
                throw new \RuntimeException('Runtime error');
            }
        };

        $command = new ClearChatCache($mockService);

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
            public function clearAllCache(): void
            {
                throw new \InvalidArgumentException('Invalid argument');
            }
        };

        $command = new ClearChatCache($mockService);

        // Set up output for the command
        $command->setOutput(new OutputStyle(
            new ArrayInput([]),
            new NullOutput
        ));

        $result = $command->handle();
        $this->assertEquals(1, $result);
    }
}
