<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class MenuImageTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(string $username = 'manager'): void
    {
        $this->seed();
        $this->actingAs(User::where('username', $username)->firstOrFail());
    }

    private function saveMenu(array $data, ?string $key = null): TestResponse
    {
        return $this->post('/api/v1/manage/menu', [
            'id' => 1, 'category_id' => 1, 'name' => 'Chicken fried rice', 'description' => 'Fresh from the wok',
            'price' => 6500, 'emoji' => '🍛', 'active' => '1', 'available' => '1', ...$data,
        ], ['Accept' => 'application/json', 'Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    public function test_manager_can_create_a_food_item_with_a_photo_and_staff_can_view_it(): void
    {
        Storage::fake('local');
        $this->signIn();
        $this->saveMenu(['id' => null, 'image' => UploadedFile::fake()->image('dish.png', 40, 30)])->assertOk();

        $food = DB::table('menu_items')->orderByDesc('id')->first();
        Storage::disk('local')->assertExists($food->image_path);
        $url = '/api/v1/menu-images/'.basename($food->image_path);
        $this->getJson('/api/v1/state')->assertJsonFragment(['image_url' => $url]);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->withSession(['password_hash_web' => null])->actingAs(User::where('username', 'waiter')->firstOrFail());
        $this->get($url)->assertOk();
    }

    public function test_editing_food_details_preserves_the_photo_until_replaced_or_removed(): void
    {
        Storage::fake('local');
        $this->signIn();
        $this->saveMenu(['image' => UploadedFile::fake()->image('first.jpg', 40, 30)])->assertOk();
        $original = DB::table('menu_items')->where('id', 1)->value('image_path');

        $this->saveMenu(['name' => 'New dish name'])->assertOk();
        $this->assertDatabaseHas('menu_items', ['id' => 1, 'name' => 'New dish name', 'image_path' => $original]);

        $this->saveMenu(['image' => UploadedFile::fake()->image('replacement.webp', 60, 50)])->assertOk();
        $replacement = DB::table('menu_items')->where('id', 1)->value('image_path');
        $this->assertNotSame($original, $replacement);
        Storage::disk('local')->assertMissing($original);
        Storage::disk('local')->assertExists($replacement);

        $this->saveMenu(['remove_image' => '1'])->assertOk();
        $this->assertDatabaseHas('menu_items', ['id' => 1, 'image_path' => null]);
        Storage::disk('local')->assertMissing($replacement);
        $this->get('/api/v1/menu-images/'.basename($replacement))->assertNotFound();
    }

    public function test_shared_photos_are_not_deleted_while_another_food_item_uses_them(): void
    {
        Storage::fake('local');
        $this->signIn();
        $file = UploadedFile::fake()->image('shared.png', 30, 20);
        $this->saveMenu(['image' => $file])->assertOk();
        $this->saveMenu(['id' => 2, 'image' => $file])->assertOk();
        $path = DB::table('menu_items')->where('id', 1)->value('image_path');
        $this->saveMenu(['remove_image' => '1'])->assertOk();

        Storage::disk('local')->assertExists($path);
        $this->assertDatabaseHas('menu_items', ['id' => 2, 'image_path' => $path]);
    }

    public function test_retried_upload_is_idempotent_and_key_reuse_with_different_content_returns_409(): void
    {
        Storage::fake('local');
        $this->signIn();
        $file = UploadedFile::fake()->image('dish.png', 30, 20);
        $key = (string) Str::uuid();
        $this->saveMenu(['image' => $file], $key)->assertOk();
        $this->saveMenu(['image' => $file], $key)->assertOk();
        $this->saveMenu(['image' => UploadedFile::fake()->image('dish.png', 31, 21)], $key)->assertConflict();
        $this->assertCount(1, Storage::disk('local')->allFiles('menu-images'));
    }

    public function test_non_image_oversized_and_excessive_dimension_uploads_return_422(): void
    {
        Storage::fake('local');
        $this->signIn();
        foreach ([
            UploadedFile::fake()->createWithContent('dish.png', '<?php echo "not an image";'),
            UploadedFile::fake()->createWithContent('dish.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'),
            UploadedFile::fake()->image('large.jpg', 30, 20)->size(5121),
            UploadedFile::fake()->image('wide.png', 6001, 1),
        ] as $image) {
            $this->saveMenu(['image' => $image])->assertUnprocessable()->assertJsonValidationErrors('image');
        }
        Storage::disk('local')->assertDirectoryEmpty('menu-images');
        $this->assertDatabaseHas('menu_items', ['id' => 1, 'image_path' => null]);
    }

    #[TestWith(['waiter'])]
    #[TestWith(['cashier'])]
    #[TestWith(['kitchen'])]
    public function test_non_managers_cannot_upload_food_photos(string $role): void
    {
        Storage::fake('local');
        $this->signIn($role);
        $this->saveMenu(['image' => UploadedFile::fake()->image('dish.jpg')])->assertForbidden();
        Storage::disk('local')->assertDirectoryEmpty('menu-images');
    }

    public function test_guests_cannot_upload_or_read_food_photos(): void
    {
        Storage::fake('local');
        $this->saveMenu(['image' => UploadedFile::fake()->image('dish.jpg')])->assertUnauthorized();
        $this->getJson('/api/v1/menu-images/'.str_repeat('a', 64).'.jpg')->assertUnauthorized();
        Storage::disk('local')->assertDirectoryEmpty('menu-images');
    }
}
