<?php

class SummaryService
{
    private static string $summaryPrompt = <<<'PROMPT'
Streszcz poniższy blok rozmowy do pamięci projektowej.

Wyciągnij:
1. nowe fakty trwałe (cele, preferencje, ważne ustalenia)
2. zmiany stanu projektu (waga, faza, trening, urazy)
3. wnioski treningowe
4. wnioski żywieniowe
5. kwestie zdrowotne
6. rzeczy chwilowe, których nie trzeba pamiętać długoterminowo

Zwróć WYŁĄCZNIE poprawny JSON bez komentarzy:
{
  "summary": "zwięzłe streszczenie rozmowy, 2-4 zdania",
  "long_term_facts": [
    {"category": "goal|nutrition|training|injury|recovery|psychology|competition|status", "content": "...", "priority": 1-10}
  ],
  "state_updates": {
    "current_weight_kg": null,
    "current_phase": null,
    "training_mode": null,
    "diet_mode": null,
    "injury_status": null,
    "next_competition": null
  },
  "temporary_notes": ["..."]
}

Zasady:
- W long_term_facts umieszczaj TYLKO fakty warte zapamiętania na miesiące
- state_updates: wpisuj TYLKO pola które się zmieniły, resztę zostaw jako null
- temporary_notes: jednorazowe obserwacje do daily_logs
- Bądź zwięzły i konkretny
PROMPT;

    /**
     * Sprawdza czy czas na nowy summary block (co 20 wiadomości)
     */
    public static function shouldSummarize(int $projectId): bool
    {
        return SummaryBlock::countUnsummarized($projectId) >= 20;
    }

    /**
     * Generuje summary block z niestreszczonych wiadomości
     */
    public static function generateSummary(int $projectId): ?int
    {
        $messages = SummaryBlock::getUnsummarizedMessages($projectId);

        if (count($messages) < 5) return null; // za mało do streszczania

        // Zbierz tekst rozmowy
        $conversationText = '';
        $firstId = $messages[0]['id'];
        $lastId = end($messages)['id'];

        foreach ($messages as $msg) {
            $role = strtoupper($msg['role']);
            $conversationText .= "[{$role}]: {$msg['content']}\n\n";
        }

        // Wyślij do gpt-5-mini
        $result = OpenAIService::extractJson(self::$summaryPrompt, $conversationText, MODEL_MINI);

        if (!$result || empty($result['summary'])) return null;

        // Zapisz summary block
        $blockId = SummaryBlock::create(
            $projectId,
            $firstId,
            $lastId,
            $result['summary'],
            $result['long_term_facts'] ?? [],
            $result['state_updates'] ?? [],
            $result['temporary_notes'] ?? []
        );

        // Przetwórz long_term_facts -> project_memory
        if (!empty($result['long_term_facts'])) {
            foreach ($result['long_term_facts'] as $fact) {
                if (empty($fact['content']) || empty($fact['category'])) continue;
                $priority = max(1, min(10, (int) ($fact['priority'] ?? 5)));

                if (!ProjectMemory::isDuplicate($projectId, $fact['category'], $fact['content'])) {
                    ProjectMemory::create($projectId, $fact['category'], $fact['content'], $priority, $blockId);
                }
            }
        }

        // Przetwórz state_updates -> project_state
        if (!empty($result['state_updates'])) {
            $updates = [];
            foreach ($result['state_updates'] as $field => $value) {
                if ($value !== null && $value !== '') {
                    $updates[$field] = $value;
                }
            }
            if (!empty($updates)) {
                ProjectState::upsert($projectId, $updates);
            }
        }

        return $blockId;
    }
}
