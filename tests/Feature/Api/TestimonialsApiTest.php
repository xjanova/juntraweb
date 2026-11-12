<?php

namespace Tests\Feature\Api;

use App\Models\Reading;
use App\Models\Testimonial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ (2026-11-12) แอพ: รีวิวชุดเดียวกับหน้าแรกของเว็บ (Testimonial::onHomePage)
 *
 * ล็อกไว้: ขึ้นได้เมื่อแอดมินอนุมัติ และถ้าลูกค้าเขียนเองต้องยอมให้โชว์ด้วย · ไม่มีข้อมูลส่วนตัวหลุด
 * · เรียงตามที่แอดมินจัด · limit 1–12 (ไม่ส่ง = 3 เท่าหน้าแรก)
 */
class TestimonialsApiTest extends TestCase
{
    use RefreshDatabase;

    private function customerReview(User $u, bool $public, bool $approved, string $message, int $order = 0): Testimonial
    {
        $reading = Reading::create([
            'user_id' => $u->id, 'session_token' => 'tok-'.uniqid(), 'type' => 'tarot_three',
            'question' => 'งานจะดีไหม', 'payload' => [], 'result' => 'ไพ่บอกว่าดี',
        ]);

        return Testimonial::create([
            'name' => 'คุณเอ', 'service' => 'ไพ่สามใบ', 'rating' => 5, 'message' => $message, 'order_index' => $order,
            'user_id' => $u->id, 'reading_id' => $reading->id, 'allow_public' => $public, 'approved' => $approved,
        ]);
    }

    public function test_only_reviews_the_home_page_shows_come_out_and_without_a_login(): void
    {
        $u = User::factory()->create(['email' => 'secret-customer@example.test']);
        $this->customerReview($u, true, true, 'ยอม');
        $this->customerReview($u, false, true, 'ไม่ยอม');
        $this->customerReview($u, true, false, 'ยังไม่อนุมัติ');
        Testimonial::create(['name' => 'แอดมิน', 'service' => 'x', 'rating' => 4, 'message' => 'เขียนเอง', 'approved' => true]);
        Testimonial::create(['name' => 'แอดมิน', 'service' => 'x', 'rating' => 5, 'message' => 'ซ่อนไว้', 'approved' => false]);

        $res = $this->getJson('/api/v1/testimonials?limit=12')->assertOk();

        $this->assertEqualsCanonicalizing(['ยอม', 'เขียนเอง'], array_column($res->json('data'), 'message'));
        $this->assertEqualsCanonicalizing(
            Testimonial::approved()->pluck('message')->all(),
            array_column($res->json('data'), 'message'),
            'ต้องเป็นชุดเดียวกับ scope approved ของหน้าแรก',
        );
    }

    public function test_items_carry_no_private_fields(): void
    {
        $u = User::factory()->create(['email' => 'secret-customer@example.test']);
        $review = $this->customerReview($u, true, true, 'ตรงมาก');

        $res = $this->getJson('/api/v1/testimonials')->assertOk()
            ->assertExactJson(['data' => [['name' => 'คุณเอ', 'service' => 'ไพ่สามใบ', 'rating' => 5, 'message' => 'ตรงมาก']]]);

        $body = $res->getContent();
        $this->assertStringNotContainsString('secret-customer', $body);
        $this->assertStringNotContainsString('user_id', $body);
        $this->assertStringNotContainsString('reading_id', $body);
        $this->assertStringContainsString('public', (string) $res->headers->get('Cache-Control'));
        $this->assertNotNull($review->reading_id);
    }

    public function test_default_limit_is_three_in_the_admin_order(): void
    {
        foreach ([5, 1, 4, 2, 3] as $order) {
            Testimonial::create(['name' => "คุณ{$order}", 'service' => 'x', 'rating' => 5, 'message' => "ลำดับ{$order}", 'approved' => true, 'order_index' => $order]);
        }

        $this->assertSame(['ลำดับ1', 'ลำดับ2', 'ลำดับ3'], array_column($this->getJson('/api/v1/testimonials')->assertOk()->json('data'), 'message'));
        $this->assertCount(1, $this->getJson('/api/v1/testimonials?limit=1')->assertOk()->json('data'));
        $this->assertCount(5, $this->getJson('/api/v1/testimonials?limit=12')->assertOk()->json('data'));
    }

    public function test_limit_out_of_bounds_is_a_thai_validation_error(): void
    {
        foreach (['0', '13', 'abc'] as $bad) {
            $res = $this->getJson('/api/v1/testimonials?limit='.$bad)->assertStatus(422)
                ->assertJsonPath('reason_code', 'validation_failed')
                ->assertJsonValidationErrors(['limit']);
            $this->assertMatchesRegularExpression('/\p{Thai}/u', $res->json('message'), "limit={$bad}");
        }
    }

    public function test_an_empty_list_is_an_empty_array(): void
    {
        $this->getJson('/api/v1/testimonials')->assertOk()->assertExactJson(['data' => []]);
    }
}
