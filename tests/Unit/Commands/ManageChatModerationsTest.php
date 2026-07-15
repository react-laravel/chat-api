<?php

namespace Tests\Unit\Commands;

use App\Console\Commands\Chat\ManageChatModerations;
use App\Models\Chat\ChatRoom;
use App\Models\Chat\ChatRoomUser;
use App\Models\User;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

class ManageChatModerationsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $user1;

    private User $user2;

    private User $moderator;

    private ChatRoom $room1;

    private ChatRoom $room2;

    private ChatRoomUser $roomUser1;

    private ChatRoomUser $roomUser2;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test users
        $this->admin = User::factory()->create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'is_admin' => true,
        ]);

        $this->user1 = User::factory()->create([
            'name' => 'Test User 1',
            'email' => 'user1@example.com',
        ]);

        $this->user2 = User::factory()->create([
            'name' => 'Test User 2',
            'email' => 'user2@example.com',
        ]);

        $this->moderator = User::factory()->create([
            'name' => 'Moderator User',
            'email' => 'moderator@example.com',
        ]);

        // Create test rooms
        $this->room1 = ChatRoom::factory()->create([
            'name' => 'Test Room 1',
            'created_by' => $this->admin->id,
        ]);

        $this->room2 = ChatRoom::factory()->create([
            'name' => 'Test Room 2',
            'created_by' => $this->admin->id,
        ]);

        // Create room user relationships
        $this->roomUser1 = ChatRoomUser::factory()->create([
            'room_id' => $this->room1->id,
            'user_id' => $this->user1->id,
            'joined_at' => now(),
            'is_online' => true,
            'is_muted' => false,
            'is_banned' => false,
        ]);

        $this->roomUser2 = ChatRoomUser::factory()->create([
            'room_id' => $this->room2->id,
            'user_id' => $this->user2->id,
            'joined_at' => now(),
            'is_online' => true,
            'is_muted' => false,
            'is_banned' => false,
        ]);
    }

    #[Test]
    public function it_can_list_moderations_when_no_moderations_exist()
    {
        $this->artisan('chat:moderation', ['action' => 'list'])
            ->expectsOutput('当前聊天室管控列表：')
            ->expectsOutput('没有发现活跃的管控。')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_can_list_muted_users()
    {
        // Create additional user and room to avoid conflicts
        $user3 = User::factory()->create(['name' => 'Test User 3', 'email' => 'user3@example.com']);
        $room3 = ChatRoom::factory()->create(['name' => 'Test Room 3', 'created_by' => $this->admin->id]);

        // Create a muted user
        $mutedUser = ChatRoomUser::factory()->create([
            'room_id' => $room3->id,
            'user_id' => $user3->id,
            'is_muted' => true,
            'muted_until' => now()->addHours(2),
            'muted_by' => $this->moderator->id,
        ]);

        $this->artisan('chat:moderation', ['action' => 'list'])
            ->expectsOutput('当前聊天室管控列表：')
            ->expectsOutput('🔇 已被静音的用户：')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_can_list_banned_users()
    {
        // Create additional user and room to avoid conflicts
        $user3 = User::factory()->create(['name' => 'Test User 3', 'email' => 'user3@example.com']);
        $room3 = ChatRoom::factory()->create(['name' => 'Test Room 3', 'created_by' => $this->admin->id]);

        // Create a banned user
        $bannedUser = ChatRoomUser::factory()->create([
            'room_id' => $room3->id,
            'user_id' => $user3->id,
            'is_banned' => true,
            'banned_until' => now()->addDays(1),
            'banned_by' => $this->moderator->id,
        ]);

        $this->artisan('chat:moderation', ['action' => 'list'])
            ->expectsOutput('当前聊天室管控列表：')
            ->expectsOutput('🚫 已被封禁的用户：')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_can_list_both_muted_and_banned_users()
    {
        // Create additional users and rooms to avoid conflicts
        $user3 = User::factory()->create(['name' => 'Test User 3', 'email' => 'user3@example.com']);
        $user4 = User::factory()->create(['name' => 'Test User 4', 'email' => 'user4@example.com']);
        $room3 = ChatRoom::factory()->create(['name' => 'Test Room 3', 'created_by' => $this->admin->id]);
        $room4 = ChatRoom::factory()->create(['name' => 'Test Room 4', 'created_by' => $this->admin->id]);

        // Create a muted user
        ChatRoomUser::factory()->create([
            'room_id' => $room3->id,
            'user_id' => $user3->id,
            'is_muted' => true,
            'muted_until' => now()->addHours(2),
            'muted_by' => $this->moderator->id,
        ]);

        // Create a banned user
        ChatRoomUser::factory()->create([
            'room_id' => $room4->id,
            'user_id' => $user4->id,
            'is_banned' => true,
            'banned_until' => now()->addDays(1),
            'banned_by' => $this->moderator->id,
        ]);

        $this->artisan('chat:moderation', ['action' => 'list'])
            ->expectsOutput('当前聊天室管控列表：')
            ->expectsOutput('🔇 已被静音的用户：')
            ->expectsOutput('🚫 已被封禁的用户：')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_can_unmute_user_by_id()
    {
        // Create additional user and room to avoid conflicts
        $user3 = User::factory()->create(['name' => 'Test User 3', 'email' => 'user3@example.com']);
        $room3 = ChatRoom::factory()->create(['name' => 'Test Room 3', 'created_by' => $this->admin->id]);

        // Create a muted user
        $mutedUser = ChatRoomUser::factory()->create([
            'room_id' => $room3->id,
            'user_id' => $user3->id,
            'is_muted' => true,
            'muted_until' => now()->addHours(2),
            'muted_by' => $this->moderator->id,
        ]);

        $this->artisan('chat:moderation', [
            'action' => 'unmute',
            '--user' => $user3->id,
            '--room' => $room3->id,
        ])
            ->expectsOutput("已成功取消用户 {$user3->name} 在房间 {$room3->name} 的静音。")
            ->assertExitCode(0);

        $this->assertDatabaseHas('chat_room_users', [
            'id' => $mutedUser->id,
            'is_muted' => false,
        ]);
    }

    #[Test]
    public function it_can_unmute_user_by_email()
    {
        // Create additional user and room to avoid conflicts
        $user3 = User::factory()->create(['name' => 'Test User 3', 'email' => 'user3@example.com']);
        $room3 = ChatRoom::factory()->create(['name' => 'Test Room 3', 'created_by' => $this->admin->id]);

        // Create a muted user
        $mutedUser = ChatRoomUser::factory()->create([
            'room_id' => $room3->id,
            'user_id' => $user3->id,
            'is_muted' => true,
            'muted_until' => now()->addHours(2),
            'muted_by' => $this->moderator->id,
        ]);

        $this->artisan('chat:moderation', [
            'action' => 'unmute',
            '--user' => $user3->email,
            '--room' => $room3->id,
        ])
            ->expectsOutput("已成功取消用户 {$user3->name} 在房间 {$room3->name} 的静音。")
            ->assertExitCode(0);

        $this->assertDatabaseHas('chat_room_users', [
            'id' => $mutedUser->id,
            'is_muted' => false,
        ]);
    }

    #[Test]
    public function it_can_unmute_all_users()
    {
        // Create additional users and rooms to avoid conflicts
        $user3 = User::factory()->create(['name' => 'Test User 3', 'email' => 'user3@example.com']);
        $user4 = User::factory()->create(['name' => 'Test User 4', 'email' => 'user4@example.com']);
        $room3 = ChatRoom::factory()->create(['name' => 'Test Room 3', 'created_by' => $this->admin->id]);
        $room4 = ChatRoom::factory()->create(['name' => 'Test Room 4', 'created_by' => $this->admin->id]);

        // Create multiple muted users
        ChatRoomUser::factory()->create([
            'room_id' => $room3->id,
            'user_id' => $user3->id,
            'is_muted' => true,
            'muted_until' => now()->addHours(2),
        ]);

        ChatRoomUser::factory()->create([
            'room_id' => $room4->id,
            'user_id' => $user4->id,
            'is_muted' => true,
            'muted_until' => now()->addHours(1),
        ]);

        $this->artisan('chat:moderation', [
            'action' => 'unmute',
            '--all' => true,
        ])
            ->expectsOutput('已取消全部房间共 2 个用户的静音。')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('chat_room_users', [
            'is_muted' => true,
        ]);
    }

    #[Test]
    public function it_returns_error_when_unmuting_without_user_and_room()
    {
        $this->artisan('chat:moderation', ['action' => 'unmute'])
            ->expectsOutput('请指定 --user 和 --room，或者使用 --all 取消全部静音')
            ->assertExitCode(1);
    }

    #[Test]
    public function it_returns_error_when_user_not_found_for_unmute()
    {
        $this->artisan('chat:moderation', [
            'action' => 'unmute',
            '--user' => '999999',
            '--room' => $this->room1->id,
        ])
            ->expectsOutput('未找到用户：999999')
            ->assertExitCode(1);
    }

    #[Test]
    public function it_returns_error_when_room_not_found_for_unmute()
    {
        $this->artisan('chat:moderation', [
            'action' => 'unmute',
            '--user' => $this->user1->id,
            '--room' => 'NonExistentRoom',
        ])
            ->expectsOutput('未找到房间：NonExistentRoom')
            ->assertExitCode(1);
    }

    #[Test]
    public function it_returns_error_when_user_not_in_room_for_unmute()
    {
        $this->artisan('chat:moderation', [
            'action' => 'unmute',
            '--user' => $this->user1->id,
            '--room' => $this->room2->id,
        ])
            ->expectsOutput("用户 {$this->user1->name} 不在房间 {$this->room2->name} 中。")
            ->assertExitCode(1);
    }

    #[Test]
    public function it_returns_success_when_user_not_muted()
    {
        $this->artisan('chat:moderation', [
            'action' => 'unmute',
            '--user' => $this->user1->id,
            '--room' => $this->room1->id,
        ])
            ->expectsOutput("用户 {$this->user1->name} 在房间 {$this->room1->name} 没有被静音。")
            ->assertExitCode(0);
    }

    #[Test]
    public function it_can_unban_user_by_id()
    {
        // Create additional user and room to avoid conflicts
        $user3 = User::factory()->create(['name' => 'Test User 3', 'email' => 'user3@example.com']);
        $room3 = ChatRoom::factory()->create(['name' => 'Test Room 3', 'created_by' => $this->admin->id]);

        // Create a banned user
        $bannedUser = ChatRoomUser::factory()->create([
            'room_id' => $room3->id,
            'user_id' => $user3->id,
            'is_banned' => true,
            'banned_until' => now()->addDays(1),
            'banned_by' => $this->moderator->id,
        ]);

        $this->artisan('chat:moderation', [
            'action' => 'unban',
            '--user' => $user3->id,
            '--room' => $room3->id,
        ])
            ->expectsOutput("已成功解除用户 {$user3->name} 在房间 {$room3->name} 的封禁。")
            ->assertExitCode(0);

        $this->assertDatabaseHas('chat_room_users', [
            'id' => $bannedUser->id,
            'is_banned' => false,
        ]);
    }

    #[Test]
    public function it_can_unban_user_by_email()
    {
        // Create additional user and room to avoid conflicts
        $user3 = User::factory()->create(['name' => 'Test User 3', 'email' => 'user3@example.com']);
        $room3 = ChatRoom::factory()->create(['name' => 'Test Room 3', 'created_by' => $this->admin->id]);

        // Create a banned user
        $bannedUser = ChatRoomUser::factory()->create([
            'room_id' => $room3->id,
            'user_id' => $user3->id,
            'is_banned' => true,
            'banned_until' => now()->addDays(1),
            'banned_by' => $this->moderator->id,
        ]);

        $this->artisan('chat:moderation', [
            'action' => 'unban',
            '--user' => $user3->email,
            '--room' => $room3->id,
        ])
            ->expectsOutput("已成功解除用户 {$user3->name} 在房间 {$room3->name} 的封禁。")
            ->assertExitCode(0);

        $this->assertDatabaseHas('chat_room_users', [
            'id' => $bannedUser->id,
            'is_banned' => false,
        ]);
    }

    #[Test]
    public function it_can_unban_all_users()
    {
        // Create additional users and rooms to avoid conflicts
        $user3 = User::factory()->create(['name' => 'Test User 3', 'email' => 'user3@example.com']);
        $user4 = User::factory()->create(['name' => 'Test User 4', 'email' => 'user4@example.com']);
        $room3 = ChatRoom::factory()->create(['name' => 'Test Room 3', 'created_by' => $this->admin->id]);
        $room4 = ChatRoom::factory()->create(['name' => 'Test Room 4', 'created_by' => $this->admin->id]);

        // Create multiple banned users
        ChatRoomUser::factory()->create([
            'room_id' => $room3->id,
            'user_id' => $user3->id,
            'is_banned' => true,
            'banned_until' => now()->addDays(1),
        ]);

        ChatRoomUser::factory()->create([
            'room_id' => $room4->id,
            'user_id' => $user4->id,
            'is_banned' => true,
            'banned_until' => now()->addDays(2),
        ]);

        $this->artisan('chat:moderation', [
            'action' => 'unban',
            '--all' => true,
        ])
            ->expectsOutput('已解除全部房间共 2 个用户的封禁。')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('chat_room_users', [
            'is_banned' => true,
        ]);
    }

    #[Test]
    public function it_returns_error_when_unbanning_without_user_and_room()
    {
        $this->artisan('chat:moderation', ['action' => 'unban'])
            ->expectsOutput('请指定 --user 和 --room，或者使用 --all 解除全部封禁')
            ->assertExitCode(1);
    }

    #[Test]
    public function it_returns_error_when_user_not_found_for_unban()
    {
        $this->artisan('chat:moderation', [
            'action' => 'unban',
            '--user' => '999999',
            '--room' => $this->room1->id,
        ])
            ->expectsOutput('未找到用户：999999')
            ->assertExitCode(1);
    }

    #[Test]
    public function it_returns_error_when_room_not_found_for_unban()
    {
        $this->artisan('chat:moderation', [
            'action' => 'unban',
            '--user' => $this->user1->id,
            '--room' => 'NonExistentRoom',
        ])
            ->expectsOutput('未找到房间：NonExistentRoom')
            ->assertExitCode(1);
    }

    #[Test]
    public function it_returns_error_when_user_not_in_room_for_unban()
    {
        $this->artisan('chat:moderation', [
            'action' => 'unban',
            '--user' => $this->user1->id,
            '--room' => $this->room2->id,
        ])
            ->expectsOutput("用户 {$this->user1->name} 不在房间 {$this->room2->name} 中。")
            ->assertExitCode(1);
    }

    #[Test]
    public function it_returns_success_when_user_not_banned()
    {
        $this->artisan('chat:moderation', [
            'action' => 'unban',
            '--user' => $this->user1->id,
            '--room' => $this->room1->id,
        ])
            ->expectsOutput("用户 {$this->user1->name} 在房间 {$this->room1->name} 没有被封禁。")
            ->assertExitCode(0);
    }

    #[Test]
    public function it_can_cleanup_expired_moderations()
    {
        Carbon::setTestNow(now());

        // Create additional users and rooms to avoid conflicts
        $user3 = User::factory()->create(['name' => 'Test User 3', 'email' => 'user3@example.com']);
        $user4 = User::factory()->create(['name' => 'Test User 4', 'email' => 'user4@example.com']);
        $user5 = User::factory()->create(['name' => 'Test User 5', 'email' => 'user5@example.com']);
        $user6 = User::factory()->create(['name' => 'Test User 6', 'email' => 'user6@example.com']);
        $room3 = ChatRoom::factory()->create(['name' => 'Test Room 3', 'created_by' => $this->admin->id]);
        $room4 = ChatRoom::factory()->create(['name' => 'Test Room 4', 'created_by' => $this->admin->id]);
        $room5 = ChatRoom::factory()->create(['name' => 'Test Room 5', 'created_by' => $this->admin->id]);
        $room6 = ChatRoom::factory()->create(['name' => 'Test Room 6', 'created_by' => $this->admin->id]);

        // Create expired mutes
        ChatRoomUser::factory()->create([
            'room_id' => $room3->id,
            'user_id' => $user3->id,
            'is_muted' => true,
            'muted_until' => now()->subHours(1), // Expired
        ]);

        ChatRoomUser::factory()->create([
            'room_id' => $room4->id,
            'user_id' => $user4->id,
            'is_muted' => true,
            'muted_until' => now()->subMinutes(30), // Expired
        ]);

        // Create expired bans
        ChatRoomUser::factory()->create([
            'room_id' => $room5->id,
            'user_id' => $user5->id,
            'is_banned' => true,
            'banned_until' => now()->subDays(1), // Expired
        ]);

        // Create active moderations (should not be cleaned up)
        ChatRoomUser::factory()->create([
            'room_id' => $room6->id,
            'user_id' => $user6->id,
            'is_muted' => true,
            'muted_until' => now()->addHours(1), // Not expired
        ]);

        $this->artisan('chat:moderation', ['action' => 'cleanup'])
            ->expectsOutput('清理了 3 条已过期的管控(静音：2，封禁：1)')
            ->assertExitCode(0);

        // Check that expired moderations were cleaned up
        $this->assertDatabaseMissing('chat_room_users', [
            'is_muted' => true,
            'muted_until' => now()->subHours(1),
        ]);

        $this->assertDatabaseMissing('chat_room_users', [
            'is_banned' => true,
            'banned_until' => now()->subDays(1),
        ]);

        // Check that active moderations were not cleaned up
        $this->assertDatabaseHas('chat_room_users', [
            'is_muted' => true,
            'muted_until' => now()->addHours(1),
        ]);

        Carbon::setTestNow();
    }

    #[Test]
    public function it_returns_error_for_unknown_action()
    {
        $this->artisan('chat:moderation', ['action' => 'unknown'])
            ->expectsOutput('未知操作：unknown')
            ->expectsOutput('可用操作：list, unmute, unban, cleanup')
            ->assertExitCode(1);
    }

    #[Test]
    public function it_can_find_user_by_id()
    {
        $command = new ManageChatModerations;
        $command->setLaravel(app());
        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('findUser');
        $method->setAccessible(true);

        $result = $method->invoke($command, $this->user1->id);
        $this->assertEquals($this->user1->id, $result->id);
    }

    #[Test]
    public function it_can_find_user_by_email()
    {
        $command = new ManageChatModerations;
        $command->setLaravel(app());
        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('findUser');
        $method->setAccessible(true);

        $result = $method->invoke($command, $this->user1->email);
        $this->assertEquals($this->user1->id, $result->id);
    }

    #[Test]
    public function it_returns_null_for_nonexistent_user()
    {
        $command = new ManageChatModerations;
        $command->setLaravel(app());
        $command->setOutput(new OutputStyle(
            new ArrayInput([]),
            new NullOutput
        ));
        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('findUser');
        $method->setAccessible(true);

        $result = $method->invoke($command, 'nonexistent@example.com');
        $this->assertNull($result);
    }

    #[Test]
    public function it_can_find_room_by_id()
    {
        $command = new ManageChatModerations;
        $command->setLaravel(app());
        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('findRoom');
        $method->setAccessible(true);

        $result = $method->invoke($command, $this->room1->id);
        $this->assertEquals($this->room1->id, $result->id);
    }

    #[Test]
    public function it_can_find_room_by_name()
    {
        $command = new ManageChatModerations;
        $command->setLaravel(app());
        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('findRoom');
        $method->setAccessible(true);

        $result = $method->invoke($command, $this->room1->name);
        $this->assertEquals($this->room1->id, $result->id);
    }

    #[Test]
    public function it_returns_null_for_nonexistent_room()
    {
        $command = new ManageChatModerations;
        $command->setLaravel(app());
        $command->setOutput(new OutputStyle(
            new ArrayInput([]),
            new NullOutput
        ));
        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('findRoom');
        $method->setAccessible(true);

        $result = $method->invoke($command, 'NonexistentRoom');
        $this->assertNull($result);
    }

    #[Test]
    public function it_handles_permanent_moderations_in_list()
    {
        // Create additional users and rooms to avoid conflicts
        $user3 = User::factory()->create(['name' => 'Test User 3', 'email' => 'user3@example.com']);
        $user4 = User::factory()->create(['name' => 'Test User 4', 'email' => 'user4@example.com']);
        $room3 = ChatRoom::factory()->create(['name' => 'Test Room 3', 'created_by' => $this->admin->id]);
        $room4 = ChatRoom::factory()->create(['name' => 'Test Room 4', 'created_by' => $this->admin->id]);

        // Create a permanently muted user
        ChatRoomUser::factory()->create([
            'room_id' => $room3->id,
            'user_id' => $user3->id,
            'is_muted' => true,
            'muted_until' => null, // Permanent
            'muted_by' => $this->moderator->id,
        ]);

        // Create a permanently banned user
        ChatRoomUser::factory()->create([
            'room_id' => $room4->id,
            'user_id' => $user4->id,
            'is_banned' => true,
            'banned_until' => null, // Permanent
            'banned_by' => $this->moderator->id,
        ]);

        $this->artisan('chat:moderation', ['action' => 'list'])
            ->expectsOutput('当前聊天室管控列表：')
            ->expectsOutput('🔇 已被静音的用户：')
            ->expectsOutput('🚫 已被封禁的用户：')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_handles_expired_moderations_in_list()
    {
        // Create additional users and rooms to avoid conflicts
        $user3 = User::factory()->create(['name' => 'Test User 3', 'email' => 'user3@example.com']);
        $user4 = User::factory()->create(['name' => 'Test User 4', 'email' => 'user4@example.com']);
        $room3 = ChatRoom::factory()->create(['name' => 'Test Room 3', 'created_by' => $this->admin->id]);
        $room4 = ChatRoom::factory()->create(['name' => 'Test Room 4', 'created_by' => $this->admin->id]);

        // Create an expired muted user
        ChatRoomUser::factory()->create([
            'room_id' => $room3->id,
            'user_id' => $user3->id,
            'is_muted' => true,
            'muted_until' => now()->subHours(1), // Expired
            'muted_by' => $this->moderator->id,
        ]);

        // Create an expired banned user
        ChatRoomUser::factory()->create([
            'room_id' => $room4->id,
            'user_id' => $user4->id,
            'is_banned' => true,
            'banned_until' => now()->subDays(1), // Expired
            'banned_by' => $this->moderator->id,
        ]);

        $this->artisan('chat:moderation', ['action' => 'list'])
            ->expectsOutput('当前聊天室管控列表：')
            ->expectsOutput('🔇 已被静音的用户：')
            ->expectsOutput('🚫 已被封禁的用户：')
            ->assertExitCode(0);
    }
}
