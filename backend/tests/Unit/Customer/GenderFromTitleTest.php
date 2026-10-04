<?php

declare(strict_types=1);

namespace Tests\Unit\Customer;

use App\Support\GenderFromTitle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GenderFromTitleTest extends TestCase
{
    public static function titles(): array
    {
        return [
            'นาย' => ['นาย', 'M'],
            'นาง' => ['นาง', 'F'],
            'นางสาว' => ['นางสาว', 'F'],
            'น.ส.' => ['น.ส.', 'F'],
            'เด็กชาย' => ['เด็กชาย', 'M'],
            'เด็กหญิง' => ['เด็กหญิง', 'F'],
            'นายแพทย์' => ['นายแพทย์', 'M'],
            'แพทย์หญิง' => ['แพทย์หญิง', 'F'],
            'พญ.' => ['พญ.', 'F'],
            'นพ.' => ['นพ.', 'M'],
            'rank' => ['ร.ต.อ.', 'M'],
            'rank female' => ['ร.ต.อ.หญิง', 'F'],
            'ว่าที่ร้อยตรี' => ['ว่าที่ร้อยตรี', 'M'],
            'Mr.' => ['Mr.', 'M'],
            'Mrs.' => ['Mrs.', 'F'],
            'Ms' => ['Ms', 'F'],
            'Miss' => ['Miss', 'F'],
            'Master' => ['Master', 'M'],
            'คุณ' => ['คุณ', null],
            'บริษัท' => ['บริษัท', null],
            'ดร.' => ['ดร.', null],
            'empty' => ['', null],
            'null' => [null, null],
        ];
    }

    #[DataProvider('titles')]
    public function test_guess(?string $title, ?string $expected): void
    {
        $this->assertSame($expected, GenderFromTitle::guess($title));
    }

    public function test_normalize_legacy_values(): void
    {
        $this->assertSame('M', GenderFromTitle::normalize('male'));
        $this->assertSame('M', GenderFromTitle::normalize('ชาย'));
        $this->assertSame('F', GenderFromTitle::normalize('Female'));
        $this->assertSame('F', GenderFromTitle::normalize('F'));
        $this->assertNull(GenderFromTitle::normalize('other'));
        $this->assertNull(GenderFromTitle::normalize(''));
    }
}
