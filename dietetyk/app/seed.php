<?php

/**
 * Dane startowe - importuje projekt Armwrestling 84kg
 * z trwałą pamięcią projektu i stanem
 */

$existing = Database::get()->query("SELECT COUNT(*) FROM projects")->fetchColumn();
if ($existing > 0) return;

$seedUserId = Auth::currentUserId() ?? 1;

// Utwórz projekt
$projectId = Project::create(
    'Armwrestling 84 kg',
    'Redukcja masy ciała z 98 kg do 84 kg przy zachowaniu siły armwrestlerskiej',
    $seedUserId
);

// === Project state (snapshot) ===
ProjectState::upsert($projectId, [
    'start_weight_kg' => 98.0,
    'current_weight_kg' => 95.1,
    'target_weight_kg' => 84.0,
    'height_cm' => 187,
    'age' => 30,
    'sex' => 'M',
    'pal' => 1.2,
    'current_phase' => 'Redukcja',
    'next_competition' => 'Mistrzostwa Polski 27-29 marca',
    'training_mode' => 'Tylko gumy do MP z powodu przeciążenia ścięgien i nadgarstków',
    'diet_mode' => 'Bez liczenia kalorii, jedzenie funkcyjne, bez słodyczy i napojów kolorowych',
    'injury_status' => 'Przeciążenie nadgarstków (grzbiet), szczególnie po ostatnich zawodach',
]);

// === Durable project memory ===
$memory = [
    ['category' => 'goal',        'priority' => 10, 'content' => 'Cel główny: stabilne 84 kg z wyprzedzeniem przed zawodami, aby trenować kilka miesięcy już jako zawodnik 84 kg.'],
    ['category' => 'competition', 'priority' => 9,  'content' => 'Najbliższe MP są traktowane jako obowiązek i test formy, bez dużej presji wyniku.'],
    ['category' => 'nutrition',   'priority' => 9,  'content' => 'Użytkownik nie chce liczyć kalorii. Lepiej działa prosty model jedzenia funkcyjnego niż ścisłe liczenie.'],
    ['category' => 'nutrition',   'priority' => 8,  'content' => 'Zbyt mała ilość węgli przez kilka dni powoduje silny głód i nieprzyjemny zapach z ust.'],
    ['category' => 'training',    'priority' => 9,  'content' => 'Największym problemem technicznym jest przegrywanie nadgarstka na starcie z toprollerami.'],
    ['category' => 'training',    'priority' => 8,  'content' => 'Problem nie wynika głównie z chwytu palców, tylko z prostowania nadgarstka i utraty linii siły.'],
    ['category' => 'training',    'priority' => 8,  'content' => 'Obecnie zawodnik jest bardziej reaktywny na starcie niż ofensywny.'],
    ['category' => 'injury',      'priority' => 10, 'content' => 'Po ostatnich zawodach mocno bolą nadgarstki, szczególnie od grzbietu. To wygląda na przeciążenie.'],
    ['category' => 'recovery',    'priority' => 9,  'content' => 'Sen bardzo mocno wpływa na głód, migreny, energię i kontrolę jedzenia.'],
    ['category' => 'psychology',  'priority' => 8,  'content' => 'Użytkownik wierzy w proces, nie działa z lęku, chce po prostu lżej żyć i odzyskać dawną sportową wersję siebie.'],
];

foreach ($memory as $item) {
    ProjectMemory::create($projectId, $item['category'], $item['content'], $item['priority']);
}

// Pierwszy checkpoint
Checkpoint::create($projectId, 95.1, null, null, null, 'Waga startowa przy rozpoczęciu projektu');
