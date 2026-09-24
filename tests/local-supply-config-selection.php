<?php
declare(strict_types=1);

require dirname(__DIR__) . '/extensions/PikaSupplySync/Service/ConfigSelection.php';

use Pika\LocalExtensions\PikaSupplySync\Service\ConfigSelection;

$checks = 0;
function selectionExpect(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function selectionHeld(array $local, array $remote, bool $price, bool $options, string $message): void
{
    selectionExpect(ConfigSelection::project($local, $remote, $price, $options)
        === ['config' => $local, 'held' => true], $message);
}

$local = [
    'category' => ['Basic' => '12.00', 'Pro' => '24.00'],
    'wholesale' => [10 => '10.00', 20 => '9.00'],
    'sku' => ['Region' => ['East' => '1.00', 'West' => '0.00']],
    'category_wholesale' => ['Basic' => [10 => '11.00'], 'Pro' => [10 => '23.00']],
    'category_cost' => ['Basic' => '8.00', 'Pro' => '18.00'],
    'sku_cost' => ['Region' => ['East' => '0.50', 'West' => '0.00']],
    'shared_mapping' => ['Basic' => 'sku-basic', 'Pro' => 'sku-pro'],
];
$remote = [
    'category' => ['Basic' => '15.00', 'Pro' => '27.00'],
    'wholesale' => [10 => '13.00', 20 => '12.00'],
    'sku' => ['Region' => ['East' => '2.00', 'West' => '0.00']],
    'category_wholesale' => ['Basic' => [10 => '14.00'], 'Pro' => [10 => '26.00']],
    'category_cost' => ['Basic' => '10.00', 'Pro' => '20.00'],
    'sku_cost' => ['Region' => ['East' => '1.00', 'West' => '0.00']],
    'shared_mapping' => ['Basic' => 'sku-basic', 'Pro' => 'sku-pro'],
];
$localBefore = $local;
$remoteBefore = $remote;
selectionExpect(ConfigSelection::project($local, $remote, true, true)
    === ['config' => $remote, 'held' => false], 'both selections must use all known remote values');
selectionExpect(ConfigSelection::project($local, $remote, false, false)
    === ['config' => $local, 'held' => false], 'both disabled must retain the entire config');
selectionExpect(ConfigSelection::project($local, $remote, false, true)
    === ['config' => $local, 'held' => false], 'options alone must retain every selling and cost amount');
selectionExpect(ConfigSelection::project($local, $remote, true, false)
    === ['config' => $remote, 'held' => false], 'prices alone may update exact existing amounts');
selectionExpect($local === $localBefore && $remote === $remoteBefore, 'projection must not mutate its inputs');

// An option selection may change display order, but never protected amounts.
$reordered = $remote;
$reordered['category'] = array_reverse($remote['category'], true);
$reordered['sku']['Region'] = array_reverse($remote['sku']['Region'], true);
$reordered['shared_mapping'] = array_reverse($remote['shared_mapping'], true);
$result = ConfigSelection::project($local, $reordered, false, true);
selectionExpect(!$result['held'] && array_keys($result['config']['category']) === ['Pro', 'Basic']
    && $result['config']['category']['Basic'] === '12.00'
    && array_keys($result['config']['sku']['Region']) === ['West', 'East']
    && $result['config']['sku']['Region']['East'] === '1.00', 'option ordering must preserve exact local prices');
$result = ConfigSelection::project($local, $reordered, true, false);
selectionExpect(!$result['held'] && array_keys($result['config']['category']) === ['Basic', 'Pro']
    && array_keys($result['config']['sku']['Region']) === ['East', 'West']
    && $result['config']['shared_mapping'] === $local['shared_mapping'], 'price selection must preserve local option order and mapping');

// Every changed price-tree shape is held atomically when price is protected.
foreach (['category', 'wholesale', 'category_cost'] as $section) {
    $added = $remote;
    $added[$section]['New'] = '3.00';
    if ($section === 'category') {
        $added['shared_mapping']['New'] = 'sku-new';
    }
    selectionHeld($local, $added, false, true, 'added flat key must not receive a guessed price: ' . $section);
    $removed = $remote;
    $first = array_key_first($removed[$section]);
    unset($removed[$section][$first]);
    if ($section === 'category') {
        unset($removed['shared_mapping'][$first]);
    }
    selectionHeld($local, $removed, false, true, 'deleted flat key must not remove protected pricing: ' . $section);
}
foreach (['sku', 'category_wholesale', 'sku_cost'] as $section) {
    $added = $remote;
    $group = array_key_first($added[$section]);
    $added[$section][$group]['New'] = '3.00';
    selectionHeld($local, $added, false, true, 'added nested key must be held: ' . $section);
    $removed = $remote;
    unset($removed[$section][$group][array_key_first($removed[$section][$group])]);
    selectionHeld($local, $removed, false, true, 'deleted nested key must be held: ' . $section);
    $renamed = $remote;
    $renamed[$section]['Renamed'] = $renamed[$section][$group];
    unset($renamed[$section][$group]);
    selectionHeld($local, $renamed, false, true, 'renamed nested group must be held: ' . $section);
}
$renamed = $remote;
$renamed['category']['Renamed'] = $renamed['category']['Basic'];
$renamed['shared_mapping']['Renamed'] = $renamed['shared_mapping']['Basic'];
unset($renamed['category']['Basic'], $renamed['shared_mapping']['Basic']);
selectionHeld($local, $renamed, false, true, 'renamed category must not be matched by price or guessed identity');
$missingCost = $local;
unset($missingCost['sku_cost']);
selectionHeld($missingCost, $remote, false, true, 'missing local cost section must not inherit upstream costs');
$identityChanged = $remote;
$identityChanged['shared_mapping']['Basic'] = 'different-sku';
selectionHeld($local, $identityChanged, false, true, 'same display name with changed fulfillment ID must be held');
$missingIdentity = $remote;
unset($missingIdentity['shared_mapping']);
selectionHeld($local, $missingIdentity, false, true, 'missing fulfillment mapping must not be guessed');

// Price-only updates are partial, and never introduce/delete an option or tier.
$changed = $remote;
$changed['category'] = ['Basic' => '15.00', 'New' => '30.00'];
$changed['shared_mapping'] = ['Basic' => 'sku-basic', 'New' => 'sku-new'];
$changed['sku']['Region'] = ['East' => '2.00', 'New' => '3.00'];
$changed['sku']['Extra'] = ['One' => '4.00'];
$changed['wholesale'] = [10 => '13.00', 30 => '8.00'];
$result = ConfigSelection::project($local, $changed, true, false);
selectionExpect($result['held'], 'unmatched prices must return partial');
selectionExpect($result['config']['category'] === ['Basic' => '15.00', 'Pro' => '24.00'], 'only matched category prices may change');
selectionExpect($result['config']['sku'] === ['Region' => ['East' => '2.00', 'West' => '0.00']], 'SKU names, groups and missing amounts must remain local');
selectionExpect($result['config']['wholesale'] === [10 => '13.00', 20 => '9.00'], 'unmatched wholesale thresholds must remain local');
selectionExpect($result['config']['shared_mapping'] === $local['shared_mapping'], 'price-only must not update fulfillment mapping');
selectionExpect($result['config']['category_cost']['Pro'] === $local['category_cost']['Pro']
    && $result['config']['category_wholesale']['Pro'] === $local['category_wholesale']['Pro'], 'unmatched category identity must protect costs and wholesale prices too');
$result = ConfigSelection::project($local, $identityChanged, true, false);
selectionExpect($result['held'] && $result['config']['category']['Basic'] === '12.00'
    && $result['config']['category_cost']['Basic'] === '8.00'
    && $result['config']['category_wholesale']['Basic'] === [10 => '11.00']
    && $result['config']['category']['Pro'] === '27.00', 'changed ID must hold related amounts while independent matches continue');
$result = ConfigSelection::project($local, $missingIdentity, true, false);
selectionExpect($result['held'] && $result['config']['category'] === $local['category']
    && $result['config']['sku'] === $remote['sku'], 'missing identity must hold category prices without blocking independent SKU matches');

// Full selection permits known additions/deletions, not arbitrary config writes.
$result = ConfigSelection::project($local, $changed, true, true);
selectionExpect(!$result['held'] && $result['config'] === $changed, 'full selection must follow known changed option sets');
$fewerSections = ['category' => ['Basic' => '5.00']];
selectionExpect(ConfigSelection::project($local, $fewerSections, true, true)
    === ['config' => $fewerSections, 'held' => false], 'full selection must remove absent supported sections');
$extraLocal = $local + ['inventory' => ['available' => 7], 'custom' => ['local' => 'keep']];
$extraRemote = $remote + ['inventory' => ['available' => 999], 'remote_only' => ['value' => 'reject']];
foreach ([[true, true], [true, false], [false, true]] as [$price, $options]) {
    $result = ConfigSelection::project($extraLocal, $extraRemote, $price, $options);
    selectionExpect($result['held'] && $result['config']['inventory'] === ['available' => 7]
        && $result['config']['custom'] === ['local' => 'keep']
        && !array_key_exists('remote_only', $result['config']), 'unknown/inventory config must stay local for every active selection');
    selectionExpect(array_keys($result) === ['config', 'held'] && !isset($result['stock'], $result['shared_stock']), 'helper must not restore commodity inventory from config');
}
selectionExpect(ConfigSelection::project($extraLocal, $extraRemote, false, false)
    === ['config' => $extraLocal, 'held' => false], 'disabled selection must not create a hold for unused unknown sections');

// Native sale/cost valuation ignores negative SKU surcharges, so preserve
// these finite sentinels without permitting negative category/tier prices.
foreach (['sku', 'sku_cost'] as $section) {
    foreach ([-1, '-0.25', -0.5] as $sentinel) {
        $negativeRemote = $remote;
        $negativeRemote[$section]['Region']['East'] = $sentinel;
        selectionExpect(ConfigSelection::project($local, $negativeRemote, true, true)
            === ['config' => $negativeRemote, 'held' => false], 'full selection must preserve native finite SKU sentinel: ' . $section);
        selectionExpect(ConfigSelection::project($local, $negativeRemote, true, false)
            === ['config' => $negativeRemote, 'held' => false], 'price-only must preserve matched finite SKU sentinel: ' . $section);
        $negativeLocal = $local;
        $negativeLocal[$section]['Region']['East'] = $sentinel;
        selectionExpect(ConfigSelection::project($negativeLocal, $remote, false, true)
            === ['config' => $negativeLocal, 'held' => false], 'protected local SKU sentinel must not cause a hold: ' . $section);
    }
    foreach ([INF, -INF, NAN, '-1e999', 'NaN', false, []] as $invalid) {
        $bad = $remote;
        $bad[$section]['Region']['East'] = $invalid;
        selectionHeld($local, $bad, true, true, 'SKU sentinel support must still reject non-finite/invalid values: ' . $section);
    }
}
foreach (['category', 'wholesale', 'category_cost', 'category_wholesale'] as $section) {
    $bad = $remote;
    $key = array_key_first($bad[$section]);
    if ($section === 'category_wholesale') {
        $bad[$section][$key][array_key_first($bad[$section][$key])] = '-1';
    } else {
        $bad[$section][$key] = '-1';
    }
    selectionHeld($local, $bad, true, true, 'ordinary negative price must remain invalid: ' . $section);
    selectionHeld($local, $bad, true, false, 'price-only must reject ordinary negative price: ' . $section);
    selectionHeld($bad, $remote, false, true, 'options-only must not reinterpret invalid inherited price: ' . $section);
}

foreach ([null, false, 'not-money', INF, NAN, ['nested' => '1.00']] as $invalid) {
    $bad = $remote;
    $bad['category']['Basic'] = $invalid;
    selectionHeld($local, $bad, true, true, 'invalid normalized amount must retain local config');
    selectionHeld($local, $bad, true, false, 'price-only invalid amount must retain local config');
    $badLocal = $local;
    $badLocal['sku']['Region']['East'] = $invalid;
    selectionHeld($badLocal, $remote, false, true, 'invalid inherited local amount must not be guessed');
}
foreach (['sku' => ['Region' => 'bad'], 'shared_mapping' => ['Basic' => []], 'category' => 'bad'] as $section => $invalid) {
    $bad = $remote;
    $bad[$section] = $invalid;
    selectionHeld($local, $bad, true, true, 'malformed known tree must be held: ' . $section);
}
$bad = $remote;
unset($bad['shared_mapping']['Pro']);
selectionHeld($local, $bad, true, true, 'incomplete V4 mapping must not be installed');
foreach ([[true, true], [true, false], [false, true], [false, false]] as [$price, $options]) {
    selectionExpect(ConfigSelection::project([], [], $price, $options)
        === ['config' => [], 'held' => false], 'empty config must remain valid');
}
selectionHeld([], $remote, false, true, 'options cannot initialize missing protected prices');
selectionHeld($local, [], false, true, 'empty remote config cannot erase protected prices');
selectionHeld([], $remote, true, false, 'price-only cannot create a new option structure');
selectionHeld($local, [], true, false, 'price-only cannot delete an existing option structure');

// Full selection accepts an unchanged complete unknown subset, without treating
// its size or values as a recognized configuration schema.
$twoUnknown = ['custom_label' => 'synthetic-label', 'custom_mode' => 'compact'];
selectionExpect(ConfigSelection::project($twoUnknown, $twoUnknown, true, true)
    === ['config' => $twoUnknown, 'held' => false], 'two identical unknown entries without price trees must not be held');
$manyUnknown = [];
for ($index = 1; $index <= 36; $index++) {
    $manyUnknown['custom_' . str_pad((string)$index, 2, '0', STR_PAD_LEFT)] = 'synthetic-value-' . $index;
}
$manyLocal = ['category' => ['Standard' => '12.00'], 'category_cost' => ['Standard' => '8.00']] + $manyUnknown;
selectionExpect(ConfigSelection::project($manyLocal, $manyLocal, true, true)
    === ['config' => $manyLocal, 'held' => false], 'identical full config with 36 unknown entries must not be held');
$manyRemote = ['category' => ['Standard' => '15.00'], 'category_cost' => ['Standard' => '10.00']] + $manyUnknown;
selectionExpect(ConfigSelection::project($manyLocal, $manyRemote, true, true)
    === ['config' => $manyRemote, 'held' => false], 'identical 36-entry unknown subset must allow valid known price changes');
$manyRemoteReorderedKnown = ['category_cost' => $manyRemote['category_cost']] + $manyUnknown
    + ['category' => $manyRemote['category']];
selectionExpect(ConfigSelection::project($manyLocal, $manyRemoteReorderedKnown, true, true)
    === ['config' => $manyRemote, 'held' => false], 'known section order must not change equality of the unknown subset');

$strictUnknown = [
    'custom_label' => '7',
    'custom_nested' => ['first' => ['enabled' => true], 'second' => ['rank' => 2]],
    'custom_empty' => [],
];
$identicalLocal = $local + $strictUnknown;
$identicalRemote = $remote + $strictUnknown;
$identicalLocalBefore = $identicalLocal;
$identicalRemoteBefore = $identicalRemote;
selectionExpect(ConfigSelection::project($identicalLocal, $identicalRemote, true, true)
    === ['config' => $identicalRemote, 'held' => false], 'identical nested unknown values must allow all valid known projections');
selectionExpect($identicalLocal === $identicalLocalBefore && $identicalRemote === $identicalRemoteBefore,
    'unknown subset comparison must not mutate or sort either input');

$unknownChanges = [];
$unknownChanges['added section'] = [$strictUnknown, $strictUnknown + ['custom_added' => 'new']];
$removedUnknown = $strictUnknown;
unset($removedUnknown['custom_label']);
$unknownChanges['removed section'] = [$strictUnknown, $removedUnknown];
$renamedUnknown = ['custom_renamed' => $strictUnknown['custom_label']] + $removedUnknown;
$unknownChanges['renamed section'] = [$strictUnknown, $renamedUnknown];
$unknownChanges['empty section removed'] = [['custom_empty' => []], []];
$unknownChanges['empty section added'] = [[], ['custom_empty' => []]];
$unknownChanges['changed scalar value'] = [$strictUnknown, array_replace($strictUnknown, ['custom_label' => '8'])];
$unknownChanges['string changed to integer'] = [$strictUnknown, array_replace($strictUnknown, ['custom_label' => 7])];
$unknownChanges['integer changed to float'] = [['custom_value' => 1], ['custom_value' => 1.0]];
$unknownChanges['boolean changed to integer'] = [['custom_value' => false], ['custom_value' => 0]];
$unknownChanges['null changed to empty string'] = [['custom_value' => null], ['custom_value' => '']];
$unknownChanges['array changed to scalar'] = [['custom_value' => []], ['custom_value' => '']];
$nestedChanged = $strictUnknown;
$nestedChanged['custom_nested']['first']['enabled'] = false;
$unknownChanges['changed nested value'] = [$strictUnknown, $nestedChanged];
$nestedTypeChanged = $strictUnknown;
$nestedTypeChanged['custom_nested']['second']['rank'] = '2';
$unknownChanges['changed nested type'] = [$strictUnknown, $nestedTypeChanged];
$nestedAdded = $strictUnknown;
$nestedAdded['custom_nested']['third'] = ['rank' => 3];
$unknownChanges['added nested key'] = [$strictUnknown, $nestedAdded];
$nestedRemoved = $strictUnknown;
unset($nestedRemoved['custom_nested']['second']);
$unknownChanges['removed nested key'] = [$strictUnknown, $nestedRemoved];
$nestedRenamed = $strictUnknown;
$nestedRenamed['custom_nested']['renamed'] = $nestedRenamed['custom_nested']['second'];
unset($nestedRenamed['custom_nested']['second']);
$unknownChanges['renamed nested key'] = [$strictUnknown, $nestedRenamed];
$unknownChanges['reordered sections'] = [$strictUnknown, array_reverse($strictUnknown, true)];
$nestedReordered = $strictUnknown;
$nestedReordered['custom_nested'] = array_reverse($nestedReordered['custom_nested'], true);
$unknownChanges['reordered nested keys'] = [$strictUnknown, $nestedReordered];
$unknownChanges['reordered list values'] = [['custom_list' => ['first', 'second']], ['custom_list' => ['second', 'first']]];
$unknownChanges['changed integer key to string key'] = [['custom_list' => [1 => 'value']], ['custom_list' => ['01' => 'value']]];
foreach ($unknownChanges as $label => [$localUnknown, $remoteUnknown]) {
    $caseLocal = $local + $localUnknown;
    $caseRemote = $remote + $remoteUnknown;
    $caseLocalBefore = $caseLocal;
    $caseRemoteBefore = $caseRemote;
    selectionExpect(ConfigSelection::project($caseLocal, $caseRemote, true, true)
        === ['config' => $remote + $localUnknown, 'held' => true],
        'different unknown subset must be held and remain local while known prices project: ' . $label);
    selectionHeld($localUnknown, $remoteUnknown, true, true,
        'different unknown-only config must remain exactly local: ' . $label);
    selectionExpect($caseLocal === $caseLocalBefore && $caseRemote === $caseRemoteBefore,
        'unknown comparison must retain original PHP types and order: ' . $label);
}

// Equality is not an alternative to the existing known-tree validation gate.
$invalidKnown = [
    'non-array known tree' => ['category' => 'invalid-tree'],
    'wrong nested depth' => ['sku' => ['Region' => '1.00']],
    'nested value in flat tree' => ['category' => ['Basic' => ['amount' => '1.00']]],
    'non-numeric amount' => ['category' => ['Basic' => 'not-money', 'Pro' => '27.00']],
    'non-finite amount' => ['category' => ['Basic' => INF, 'Pro' => '27.00']],
    'negative category amount' => ['category' => ['Basic' => '-1.00', 'Pro' => '27.00']],
    'boolean amount' => ['category' => ['Basic' => false, 'Pro' => '27.00']],
    'non-array mapping' => ['shared_mapping' => 'invalid-mapping'],
    'invalid mapping identity' => ['shared_mapping' => ['Basic' => [], 'Pro' => 'sku-pro']],
    'empty mapping identity' => ['shared_mapping' => ['Basic' => '', 'Pro' => 'sku-pro']],
    'incomplete mapping' => ['shared_mapping' => ['Basic' => 'sku-basic']],
    'mapping with foreign category' => ['shared_mapping' => ['Basic' => 'sku-basic', 'Other' => 'sku-other']],
];
foreach ($invalidKnown as $label => $override) {
    $invalidWithUnknown = array_replace($remote, $override) + $strictUnknown;
    selectionHeld($identicalLocal, $invalidWithUnknown, true, true,
        'identical unknown subset must not bypass invalid known input: ' . $label);
    selectionHeld($invalidWithUnknown, $invalidWithUnknown, true, true,
        'identical full config must not bypass invalid known input: ' . $label);
}

// The exception is exclusive to both enabled selections; single selections
// retain their previous unknown-section hold and protected-field behavior.
selectionExpect(ConfigSelection::project($identicalLocal, $identicalRemote, true, false)
    === ['config' => $identicalRemote, 'held' => true], 'price-only identical unknown subset must retain its existing hold');
selectionHeld($identicalLocal, $identicalRemote, false, true,
    'options-only identical unknown subset must retain its existing hold and local prices');
foreach ([[true, false], [false, true]] as [$price, $options]) {
    selectionHeld($twoUnknown, $twoUnknown, $price, $options,
        'single selection must still hold an identical unknown-only config');
}
selectionExpect(ConfigSelection::project($identicalLocal, $identicalRemote, false, false)
    === ['config' => $identicalLocal, 'held' => false], 'disabled selection must retain identical unknown config without a hold');
selectionExpect(ConfigSelection::project($invalidWithUnknown, $invalidWithUnknown, false, false)
    === ['config' => $invalidWithUnknown, 'held' => false], 'disabled selection must retain the existing validation bypass');

// Explicit full ownership applies only after both selection and known-tree gates.
$followLocal = $local + ['custom' => ['quantity' => '007', 'nested' => ['flag' => 'local']], 'local_only' => ['note' => 'manual']];
$followRemote = $remote + ['custom' => ['quantity' => '009', 'nested' => ['flag' => 'remote']], 'remote_only' => ['ratio' => '0.25']];
selectionExpect(ConfigSelection::project($followLocal, $followRemote, true, true)['held'],
    'the default must still hold changed unknown config');
selectionExpect(ConfigSelection::project($followLocal, $followRemote, true, true, true)
    === ['config' => $followRemote, 'held' => false],
    'explicit full ownership must follow additions, changes and deletions without numeric coercion');
selectionExpect(ConfigSelection::project($followRemote, $followRemote, true, true, true)
    === ['config' => $followRemote, 'held' => false], 'full ownership projection must be idempotent');
selectionExpect(ConfigSelection::project($followLocal, [], true, true, true)
    === ['config' => [], 'held' => false], 'a valid empty remote config must remove all local config under full ownership');
foreach ([[true, false], [false, true], [false, false]] as [$price, $options]) {
    selectionExpect(ConfigSelection::project($followLocal, $followRemote, $price, $options, true)
        === ConfigSelection::project($followLocal, $followRemote, $price, $options),
        'full ownership must not weaken a disabled price or options selection');
}
foreach ($invalidKnown as $label => $override) {
    selectionExpect(ConfigSelection::project($followLocal, array_replace($followRemote, $override), true, true, true)
        === ['config' => $followLocal, 'held' => true],
        'full ownership must not bypass known price or fulfillment validation: ' . $label);
}
$followReordered = array_reverse($followRemote, true);
$followReordered['custom'] = array_reverse($followRemote['custom'], true);
selectionExpect(ConfigSelection::project($followLocal, $followReordered, true, true, true)
    === ['config' => $followReordered, 'held' => false], 'full ownership must retain the validated remote tree order');
selectionExpect(!ConfigSelection::validFullSnapshot(['category' => 'bad-tree'])
    && !ConfigSelection::validFullSnapshot(['shared_mapping' => 'bad-tree'])
    && !ConfigSelection::validFullSnapshot(['custom' => 'not-an-INI-section']),
    'full snapshot validation must reject sections that INI would silently discard');
selectionExpect(ConfigSelection::project($followLocal, $followRemote + ['scalar_section' => 'lost'], true, true, true)
    === ['config' => $followLocal, 'held' => true], 'full ownership must not silently omit an opaque scalar section');

echo json_encode(['status' => 'PASS', 'checks' => $checks, 'network' => false, 'database' => false], JSON_THROW_ON_ERROR) . PHP_EOL;
