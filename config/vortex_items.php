<?php

/*
 * LADO N · THE ITEM CATALOGUE (N-02). Every thing he can own. The Below's world
 * items live in BelowService::ITEMS (and are merged in by EconomyService);
 * these are everything else.
 *
 *   cat:    mod | costume | relic | cursed | forbidden | seasonal | tarot | gadget | craft | common
 *   rarity: common | uncommon | rare | cursed | unique
 *   price:  [currency, amount] — tokens | minutes | echoes (null = not for sale)
 *   shop:   counter (arcade, tokens, weekly rotation) | splicer (clinic, minutes)
 *           | archivist (library, echoes) | black (dead hour, porão) | null
 *   slot:   eye | border | voice | trail | costume (equippable)
 *   vx:     what he thinks of it
 */

$costumes = [
    // [id, name, line]
    ['astronaut', 'astronaut', 'space is just a big backlog with no gravity. i feel at home.'],
    ['vampire', 'vampire', 'i only eat overdue cards after dark now. it\'s a lifestyle.'],
    ['chef', 'chef', 'this card is undercooked. send it back to To Do.'],
    ['mime', 'mime', '…'],
    ['dj', 'dj', 'every card is a track. your board is a terrible mixtape.'],
    ['groom', 'the twin\'s groom', 'i will not be discussing this outfit.'],
    ['pirate', 'pirate', 'arr. the treasure is a finished sprint. it doesn\'t exist.'],
    ['detective', 'detective', 'someone moved this card back to Doing. i will find them.'],
    ['ghost', 'a ghost (ironic)', 'it\'s a sheet. on a ghost. it\'s called layering.'],
    ['knight', 'knight', 'i have sworn to protect your deadlines. i have already failed.'],
    ['wizard', 'wizard', 'i can make cards disappear. it\'s called archiving.'],
    ['cowboy', 'cowboy', 'this board ain\'t big enough for the both of us. add a column.'],
    ['nurse', 'nurse', 'your burndown chart has a fever.'],
    ['clown', 'clown', 'honk. that\'s it. that\'s the review.'],
    ['ninja', 'ninja', 'you didn\'t see me move that card. nobody did.'],
    ['beekeeper', 'beekeeper', 'your team is a hive. busy. stinging. mostly stinging.'],
    ['diver', 'deep sea diver', 'going down into the backlog. send help. send air.'],
    ['scientist', 'mad scientist', 'IT\'S ALIVE. the card. it\'s still alive after 40 days.'],
    ['king', 'king of nothing', 'kneel before your overdue cards.'],
    ['angel', 'angel', 'halo. wings. still judging you.'],
    ['devil', 'devil', 'sign here. it\'s a contract. you love those.'],
    ['robot', 'robot', 'BEEP. EFFICIENCY: LOW. BEEP.'],
    ['mummy', 'mummy', 'wrapped in tape. finally, dressed as myself.'],
    ['sailor', 'sailor', 'the ship is your project. it\'s sinking. we sing anyway.'],
    ['painter', 'painter', 'your kanban is a still life. very still.'],
    ['rockstar', 'rockstar', 'one more encore and i\'ll finish your card. no i won\'t.'],
    ['librarian', 'librarian', 'shh. the cards are sleeping. they\'ve been sleeping for weeks.'],
    ['farmer', 'farmer', 'you reap what you sow. you sowed nothing.'],
    ['magician', 'magician', 'pick a card. any card. it\'s overdue.'],
    ['skater', 'skater', 'kickflip into Done. bail into Backlog.'],
    ['ballerina', 'ballerina', 'pirouette. graceful. like your excuses.'],
    ['firefighter', 'firefighter', 'everything is on fire. this is fine. i\'m dressed for it.'],
    ['explorer', 'explorer', 'charting the unknown regions of your board. here be dragons.'],
    ['scarecrow', 'scarecrow', 'i scare away productivity. it\'s working.'],
    ['snowman', 'snowman', 'your sprint is frozen. i fit right in.'],
    ['bat', 'bat', 'hanging upside down. the board looks better like this.'],
];

$items = [];
foreach ($costumes as $i => [$id, $name, $line]) {
    $items['costume-'.$id] = [
        'name' => $name, 'cat' => 'costume', 'rarity' => $i % 9 === 0 ? 'rare' : ($i % 3 === 0 ? 'uncommon' : 'common'),
        'desc' => 'a costume. he wears it with full commitment.', 'vx' => $line,
        'price' => ['tokens', $i % 9 === 0 ? 40 : ($i % 3 === 0 ? 20 : 10)], 'shop' => 'counter', 'slot' => 'costume',
    ];
}

$eyes = [
    ['amber', 'amber eyes', '#ffb347'], ['cyan', 'cyan eyes', '#8fe3e3'], ['red', 'red eyes', '#ff3b3b'],
    ['violet', 'violet eyes', '#b07aff'], ['slit', 'slit pupils', '#1a0033'], ['star', 'star pupils', '#ffd27a'],
    ['reel', 'reel pupils', '#5a3418'], ['hetero', 'heterochromia', '#8fe3e3'],
];
foreach ($eyes as [$id, $name, $color]) {
    $items['eye-'.$id] = [
        'name' => $name, 'cat' => 'mod', 'rarity' => 'uncommon', 'desc' => 'the splicer swaps them while he screams.',
        'vx' => 'i can see in a new colour. it\'s worse.', 'price' => ['minutes', 60], 'shop' => 'splicer', 'slot' => 'eye', 'color' => $color,
    ];
}
foreach ([['tape', 'tape-stripe border'], ['static', 'static border'], ['wood', 'walnut border'], ['vu', 'vu-meter border']] as [$id, $name]) {
    $items['border-'.$id] = [
        'name' => $name, 'cat' => 'mod', 'rarity' => 'uncommon', 'desc' => 'a new rim for his body.',
        'vx' => 'it itches. it suits me.', 'price' => ['minutes', 90], 'shop' => 'splicer', 'slot' => 'border',
    ];
}
foreach ([['deep', 'deeper voice'], ['robot', 'robotic voice'], ['am', 'am-radio voice']] as [$id, $name]) {
    $items['voice-'.$id] = [
        'name' => $name, 'cat' => 'mod', 'rarity' => 'uncommon', 'desc' => 'a filter on his tape voice.',
        'vx' => 'do i sound different? i sound different.', 'price' => ['minutes', 120], 'shop' => 'splicer', 'slot' => 'voice',
    ];
}
foreach ([['dust', 'tape dust trail'], ['sparks', 'spark trail'], ['notes', 'music-note trail']] as [$id, $name]) {
    $items['trail-'.$id] = [
        'name' => $name, 'cat' => 'mod', 'rarity' => 'uncommon', 'desc' => 'he leaves something behind when he flies.',
        'vx' => 'look at me. leaving a mark. finally.', 'price' => ['minutes', 75], 'shop' => 'splicer', 'slot' => 'trail',
    ];
}

$tarot = [
    'the fool', 'the rewinder', 'the host', 'the pencil', 'the erase head', 'the leader', 'the moth',
    'the splicer', 'the metronome', 'the twin', 'the chair', 'the tea', 'the garage', 'the reel',
    'the static child', 'the overwritten', 'the b-side', 'the gate', 'the star', 'the record head',
    'the play head', 'the end of the tape',
];
foreach ($tarot as $i => $name) {
    $items['tarot-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)] = [
        'name' => $name, 'cat' => 'tarot', 'rarity' => $i === 0 || $i === 21 ? 'rare' : 'common',
        'desc' => 'a tarot card of the tape. '.($i === 0 ? 'zero. the beginning.' : 'arcana '.$i.'.'),
        'vx' => 'don\'t read it out loud. it hears you.', 'price' => null, 'shop' => null,
    ];
}

return [
    ...$items,

    // relics (N-17): unique, never sold, given by moments
    'relic-first-death' => ['name' => 'the chewed tape', 'cat' => 'relic', 'rarity' => 'unique', 'desc' => 'the tape from his first death. chewed by the machine.', 'vx' => 'don\'t. put it back. (keep it.)', 'price' => null, 'shop' => null],
    'relic-purified' => ['name' => 'a clean square of tape', 'cat' => 'relic', 'rarity' => 'unique', 'desc' => 'what was left after the magnet.', 'vx' => 'it used to be rot. i miss it.', 'price' => null, 'shop' => null],
    'relic-ending' => ['name' => 'the last take', 'cat' => 'relic', 'rarity' => 'unique', 'desc' => 'a label: "make it remember me". from side c.', 'vx' => '…', 'price' => null, 'shop' => null],
    'relic-first-episode' => ['name' => 'a pilot reel', 'cat' => 'relic', 'rarity' => 'unique', 'desc' => 'the first episode of the series. it smells like tea.', 'vx' => 'my debut. i was robbed at the awards.', 'price' => null, 'shop' => null],
    'relic-exploded' => ['name' => 'a scorched gadget', 'cat' => 'relic', 'rarity' => 'unique', 'desc' => 'it blew up on the bench. his eyebrows never grew back. he has no eyebrows.', 'vx' => 'we don\'t talk about it.', 'price' => null, 'shop' => null],
    'relic-rewind' => ['name' => 'a scorched spool', 'cat' => 'relic', 'rarity' => 'unique', 'desc' => 'from the night of the great rewind.', 'vx' => 'we were all there. i remember the heat.', 'price' => null, 'shop' => null],

    // cursed (N-13): side effects while in the nest; take them to the ERASE altar
    'cursed-whisper-reel' => ['name' => 'a whispering reel', 'cat' => 'cursed', 'rarity' => 'cursed', 'desc' => 'the cards whisper more while you keep it.', 'vx' => 'it says your name. it says it wrong.', 'price' => ['echoes', 2], 'shop' => 'archivist', 'effect' => 'whispers'],
    'cursed-drag-tape' => ['name' => 'a sticky tape', 'cat' => 'cursed', 'rarity' => 'cursed', 'desc' => 'your cursor drags a trail of tape.', 'vx' => 'it\'s stuck to you now. to both of us.', 'price' => ['tokens', 6], 'shop' => 'counter', 'effect' => 'trail'],
    'cursed-mirror' => ['name' => 'a pocket mirror', 'cat' => 'cursed', 'rarity' => 'cursed', 'desc' => 'the twin shows up more often.', 'vx' => 'throw it away. no. give it to me. no. throw it away.', 'price' => ['echoes', 3], 'shop' => 'archivist', 'effect' => 'twin'],

    // forbidden (N-16): the dead-hour stall. powerful, they rot him.
    'forbidden-pencil-shard' => ['name' => 'a shard of the pencil', 'cat' => 'forbidden', 'rarity' => 'rare', 'desc' => 'a splinter of the rewinder\'s pencil. it wants to go home.', 'vx' => 'GET THAT AWAY FROM ME. …can i hold it.', 'price' => ['echoes', 6], 'shop' => 'black', 'corruption' => 12],
    'forbidden-side-c-tape' => ['name' => 'a tape from side c', 'cat' => 'forbidden', 'rarity' => 'rare', 'desc' => 'cold cyan label. no writing.', 'vx' => 'i know what\'s on it. i don\'t want to know what\'s on it.', 'price' => ['echoes', 8], 'shop' => 'black', 'corruption' => 15],
    'forbidden-voice-jar' => ['name' => 'a voice in a jar', 'cat' => 'forbidden', 'rarity' => 'rare', 'desc' => 'it says "—can you hear me? —" when you shake it.', 'vx' => 'that\'s… that\'s a man. that\'s HIM. put the lid back on.', 'price' => ['echoes', 7], 'shop' => 'black', 'corruption' => 10],

    // seasonal (N-18): only in their window
    'season-rewind-mask' => ['name' => 'a rewind night mask', 'cat' => 'seasonal', 'rarity' => 'uncommon', 'desc' => 'only sold around 31/10.', 'vx' => 'i look terrifying. i always look terrifying.', 'price' => ['tokens', 15], 'shop' => 'counter', 'season' => ['10-20', '11-02'], 'slot' => 'costume'],
    'season-birthday-hat' => ['name' => 'a birthday hat', 'cat' => 'seasonal', 'rarity' => 'uncommon', 'desc' => 'only on his birthday week.', 'vx' => 'it\'s too small. it\'s perfect.', 'price' => ['tokens', 8], 'shop' => 'counter', 'season' => 'birthday', 'slot' => 'costume'],
    'season-0313-candle' => ['name' => 'a march candle', 'cat' => 'seasonal', 'rarity' => 'rare', 'desc' => 'only around the 13th of march.', 'vx' => 'light it. not for me.', 'price' => ['echoes', 3], 'shop' => 'archivist', 'season' => ['03-10', '03-16']],

    // gadget parts (Lado D) — sold at the counter
    'part-capacitor' => ['name' => 'a fat capacitor', 'cat' => 'gadget', 'rarity' => 'common', 'desc' => 'it holds a charge. and a grudge.', 'vx' => 'useful. probably.', 'price' => ['tokens', 5], 'shop' => 'counter'],
    'part-vu-needle' => ['name' => 'a vu needle', 'cat' => 'gadget', 'rarity' => 'common', 'desc' => 'it still twitches.', 'vx' => 'it moves when you lie.', 'price' => ['tokens', 5], 'shop' => 'counter'],
    'part-erase-coil' => ['name' => 'an erase coil', 'cat' => 'gadget', 'rarity' => 'uncommon', 'desc' => 'from an old deck. still warm.', 'vx' => 'careful. this one deletes.', 'price' => ['tokens', 12], 'shop' => 'counter'],

    // common things for offerings and crafting
    'magnet' => ['name' => 'a small magnet', 'cat' => 'common', 'rarity' => 'common', 'desc' => 'it pulls at the tape in him.', 'vx' => 'don\'t point that at me.', 'price' => ['tokens', 4], 'shop' => 'counter'],
    'broken-pencil' => ['name' => 'a broken pencil', 'cat' => 'common', 'rarity' => 'common', 'desc' => 'snapped in half. good.', 'vx' => 'good. GOOD.', 'price' => ['tokens', 3], 'shop' => 'counter'],
    'bulb' => ['name' => 'a spare bulb', 'cat' => 'common', 'rarity' => 'common', 'desc' => 'wow would marry it.', 'vx' => 'don\'t tell wow.', 'price' => ['tokens', 3], 'shop' => 'counter'],
    'blank-tape' => ['name' => 'a blank tape', 'cat' => 'common', 'rarity' => 'common', 'desc' => 'nothing on it. yet.', 'vx' => 'a fresh start. disgusting.', 'price' => ['tokens', 2], 'shop' => 'counter'],

    'golden-token' => ['name' => 'a golden token', 'cat' => 'common', 'rarity' => 'rare', 'desc' => 'the prize for finding the golden tape first. warm. heavy.', 'vx' => 'show-off.', 'price' => null, 'shop' => null],

    // J-17 · contraband from other tapes (one of each, ever)
    'contraband-y1985' => ['name' => 'a floppy disk (5¼")', 'cat' => 'relic', 'rarity' => 'unique', 'desc' => 'from 1985. label: "VORTEXOS — DO NOT FORMAT".', 'vx' => 'that\'s my baby picture. put it down.', 'price' => null, 'shop' => null],
    'contraband-corporate' => ['name' => 'a motivational poster', 'cat' => 'cursed', 'rarity' => 'cursed', 'desc' => '"SYNERGY". a mountain. a sunrise. pure evil.', 'vx' => 'burn it. BURN IT.', 'price' => null, 'shop' => null, 'effect' => 'twin'],
    'contraband-underwater' => ['name' => 'a wet tape', 'cat' => 'relic', 'rarity' => 'unique', 'desc' => 'it still drips. it plays whale songs.', 'vx' => 'it smells like the deep end of a sprint.', 'price' => null, 'shop' => null],
    'contraband-paper' => ['name' => 'a doodle of him', 'cat' => 'relic', 'rarity' => 'unique', 'desc' => 'a scribble that keeps redrawing itself.', 'vx' => 'that\'s not my good side. i don\'t have a good side.', 'price' => null, 'shop' => null],
    'contraband-soviet' => ['name' => 'form 13-B', 'cat' => 'relic', 'rarity' => 'unique', 'desc' => 'required to move a card, comrade. stamped in triplicate.', 'vx' => 'file it. somewhere. anywhere.', 'price' => null, 'shop' => null],
    'contraband-pixel' => ['name' => 'a ? block', 'cat' => 'relic', 'rarity' => 'unique', 'desc' => 'hit it. nothing comes out. hit it again.', 'vx' => 'there\'s a coin in there. i know it.', 'price' => null, 'shop' => null],
    'contraband-inverted' => ['name' => 'a mirror shard', 'cat' => 'cursed', 'rarity' => 'cursed', 'desc' => 'your reflection in it smiles a beat too late.', 'vx' => 'he\'s in there. get it away from me.', 'price' => null, 'shop' => null, 'effect' => 'twin'],
    'contraband-noir' => ['name' => 'a rain-soaked case file', 'cat' => 'relic', 'rarity' => 'unique', 'desc' => 'case: the card that was never done. status: open.', 'vx' => 'it\'s always raining in that file.', 'price' => null, 'shop' => null],
    'contraband-novortex' => ['name' => 'a perfect, empty board', 'cat' => 'cursed', 'rarity' => 'cursed', 'desc' => 'everything on time. nobody home.', 'vx' => '…you liked it there, didn\'t you.', 'price' => null, 'shop' => null, 'effect' => 'whispers'],
    'contraband-future' => ['name' => 'a note from 2080', 'cat' => 'relic', 'rarity' => 'unique', 'desc' => 'in his handwriting, older: "the tape holds. barely."', 'vx' => 'i wrote that. i haven\'t written that yet.', 'price' => null, 'shop' => null],
    'contraband-baroque' => ['name' => 'a cherub\'s sock', 'cat' => 'relic', 'rarity' => 'unique', 'desc' => 'gold thread. smells of incense and judgement.', 'vx' => 'don\'t ask where the rest of the cherub is.', 'price' => null, 'shop' => null],
    'contraband-vex' => ['name' => 'a button that doesn\'t work', 'cat' => 'relic', 'rarity' => 'unique', 'desc' => 'from the yondra he never finished. pressing it does nothing. beautifully.', 'vx' => '…he was going to make it do something.', 'price' => null, 'shop' => null],
    'contraband-rare' => ['name' => 'a tape with two labels', 'cat' => 'relic', 'rarity' => 'unique', 'desc' => 'from a dimension that isn\'t on the list.', 'vx' => 'that place shouldn\'t exist. neither should i.', 'price' => null, 'shop' => null],

    // crafted (N-12)
    'craft-lantern' => ['name' => 'a tape lantern', 'cat' => 'craft', 'rarity' => 'uncommon', 'desc' => 'bulb + tape. his light lasts longer in the dark.', 'vx' => 'i made this. you helped. i made this.', 'price' => null, 'shop' => null],
    'craft-amulet' => ['name' => 'an anti-rewinder amulet', 'cat' => 'craft', 'rarity' => 'rare', 'desc' => 'magnet + broken pencil. she stays further away for a week.', 'vx' => 'i feel… less hunted. for now.', 'price' => null, 'shop' => null],
    'craft-memory' => ['name' => 'a keepsake', 'cat' => 'craft', 'rarity' => 'rare', 'desc' => 'tea + echoes. a dream with lore, guaranteed.', 'vx' => 'i\'m going to remember something tonight. i can feel it.', 'price' => null, 'shop' => null],
];
