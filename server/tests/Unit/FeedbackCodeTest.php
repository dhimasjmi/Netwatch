<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\FeedbackCode;
use PHPUnit\Framework\TestCase;

final class FeedbackCodeTest extends TestCase
{
    public function test_generated_code_is_10_unambiguous_characters_and_unique(): void
    {
        $codes = array_map(fn () => FeedbackCode::generate(), range(1, 50));

        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{10}$/', $code);
            $this->assertSame($code, FeedbackCode::normalize($code));
        }
        $this->assertCount(50, array_unique($codes));
    }

    public function test_normalize_accepts_spaces_dashes_and_lowercase_only(): void
    {
        $this->assertSame('ABCDE23456', FeedbackCode::normalize(' abcde-23456 '));
        $this->assertNull(FeedbackCode::normalize('ABCDE2345')); // terlalu pendek
        $this->assertNull(FeedbackCode::normalize('ABCDE2345O')); // huruf O tidak ada di alfabet
        $this->assertNull(FeedbackCode::normalize("ABCDE23456'; --"));
        $this->assertNull(FeedbackCode::normalize(''));
    }

    public function test_hash_is_sha256_and_not_the_code(): void
    {
        $this->assertSame(hash('sha256', 'ABCDE23456'), FeedbackCode::hash('ABCDE23456'));
        $this->assertSame(64, strlen(FeedbackCode::hash('ABCDE23456')));
    }
}
