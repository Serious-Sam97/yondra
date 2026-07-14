<?php

use App\Services\Email\SpamSafeFormatter;

beforeEach(function () {
    $this->fmt = new SpamSafeFormatter;
});

it('strips the R$ symbol and appends the currency word', function () {
    expect($this->fmt->naturalizeMoney('Total: R$1.500,00 hoje', 'BRL'))
        ->toBe('Total: 1.500,00 reais hoje');
});

it('handles a space between symbol and number', function () {
    expect($this->fmt->naturalizeMoney('custa R$ 3000'))->toBe('custa 3000 reais');
});

it('naturalizes dollars, euros and pounds', function () {
    expect($this->fmt->naturalizeMoney('$1,500.00'))->toBe('1,500.00 dollars');
    expect($this->fmt->naturalizeMoney('US$40'))->toBe('40 dollars');
    expect($this->fmt->naturalizeMoney('€99'))->toBe('99 euros');
    expect($this->fmt->naturalizeMoney('£10'))->toBe('10 pounds');
});

it('leaves a stray symbol not attached to a number alone', function () {
    expect($this->fmt->naturalizeMoney('the $ sign by itself'))->toBe('the $ sign by itself');
});

it('leaves plain numbers untouched', function () {
    expect($this->fmt->naturalizeMoney('room 1500 on floor 3'))->toBe('room 1500 on floor 3');
});

it('softens quote / orçamento trigger words with accents', function () {
    expect($this->fmt->softenTriggerWords('Segue o orçamento solicitado'))
        ->toBe('Segue o proposta solicitado');
    expect($this->fmt->softenTriggerWords('here is your quote'))
        ->toBe('here is your proposal');
});

it('preserves capitalization when softening', function () {
    expect($this->fmt->softenTriggerWords('Orçamento anexo'))->toBe('Proposta anexo');
    expect($this->fmt->softenTriggerWords('QUOTE ready'))->toBe('PROPOSAL ready');
});

it('does not touch words that merely contain a trigger substring', function () {
    // "quoted" / "misquote" should not match the whole-word "quote".
    expect($this->fmt->softenTriggerWords('he quoted a misquote'))->toBe('he quoted a misquote');
});

it('naturalize runs both money and word rules', function () {
    expect($this->fmt->naturalize('Seu orçamento: R$2.000,00', 'BRL'))
        ->toBe('Seu proposta: 2.000,00 reais');
});

it('is idempotent', function () {
    $once = $this->fmt->naturalize('Orçamento de R$1.200,50', 'BRL');
    expect($this->fmt->naturalize($once, 'BRL'))->toBe($once);
});

it('spells out a money value with no symbol', function () {
    expect($this->fmt->money(12000.5, 'BRL'))->toBe('12,000.50 reais');
    expect($this->fmt->money(40.0, 'USD'))->toBe('40.00 dollars');
});
