<?php

use App\Support\MoneyText;

/** Montos en texto de archivos importados (Lendus/NOI): App\Support\MoneyText. */
it('reads comma thousands separators as thousands, not decimals', function () {
    expect(MoneyText::toDecimal('12,500'))->toBe(12500.0)
        ->and(MoneyText::toDecimal('1,250,000'))->toBe(1250000.0)
        ->and(MoneyText::toDecimal('$ 1,500'))->toBe(1500.0)
        ->and(MoneyText::toDecimal('-3,200'))->toBe(-3200.0);
});

it('keeps the previous behaviour for every other format', function () {
    expect(MoneyText::toDecimal('1,234.56'))->toBe(1234.56)
        ->and(MoneyText::toDecimal('1,5'))->toBe(1.5)
        ->and(MoneyText::toDecimal('12,50'))->toBe(12.5)
        ->and(MoneyText::toDecimal('1500.75'))->toBe(1500.75)
        ->and(MoneyText::toDecimal(980))->toBe(980.0)
        ->and(MoneyText::toDecimal('$ 45'))->toBe(45.0)
        ->and(MoneyText::toDecimal(''))->toBeNull()
        ->and(MoneyText::toDecimal(null))->toBeNull()
        ->and(MoneyText::toDecimal('N/A'))->toBeNull();
});
