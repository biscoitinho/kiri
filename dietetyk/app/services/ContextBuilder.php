<?php

class ContextBuilder
{
    /**
     * Buduje system prompt z 3-warstwowego kontekstu
     *
     * Kolejność w prompcie:
     * 1. System prompt (rola AI)
     * 2. Project state (snapshot)
     * 3. Durable memory (trwałe wnioski)
     * 4. Recent summary blocks (streszczenia)
     * 5. (raw messages dodawane osobno w chatWithContext)
     */
    public static function build(int $projectId): string
    {
        $project = Project::find($projectId);
        if (!$project) return '';

        $parts = [];

        // === 1. System prompt ===
        $parts[] = "[SYSTEM PROMPT]";
        $parts[] = "Jesteś osobistym AI trenerem i coachem projektu.";
        $parts[] = "Odpowiadasz po polsku. Jesteś konkretny, wspierający i merytoryczny.";
        $parts[] = "Aktualna data i czas: " . date('Y-m-d H:i (l)', time());
        $parts[] = "Projekt: {$project['name']}";
        if ($project['description']) {
            $parts[] = "Opis: {$project['description']}";
        }
        $parts[] = "";

        // === 2. Project state (snapshot) ===
        $projectState = ProjectState::get($projectId) ?? [];
        $stateBlock = ProjectState::toPromptBlock($projectId);
        if ($stateBlock) {
            $parts[] = $stateBlock;
            $parts[] = "";
        }

        // === 3. Durable memory (top 15 wg priorytetu) ===
        $memory = ProjectMemory::getTop($projectId, 15);
        if ($memory) {
            $parts[] = "[DURABLE MEMORY]";
            foreach ($memory as $item) {
                $parts[] = "- [{$item['category']}] (P:{$item['priority']}) {$item['content']}";
            }
            $parts[] = "";
        }

        // === 4. Recent summary blocks (2-3 najnowsze) ===
        $summaries = SummaryBlock::getRecent($projectId, 3);
        if ($summaries) {
            $parts[] = "[RECENT SUMMARY BLOCKS]";
            foreach ($summaries as $s) {
                $parts[] = "- {$s['summary']}";
            }
            $parts[] = "";
        }

        // === Instrukcje ===
        $parts[] = "[INSTRUKCJE]";
        $parts[] = "- Bazuj na powyższym kontekście przy odpowiedziach";
        $parts[] = "- Bądź bezpośredni, unikaj ogólników";
        $parts[] = "- Jeśli użytkownik podaje nowe dane (waga, sen, trening), zanotuj to";
        $parts[] = "";
        $parts[] = "[FORMAT ODPOWIEDZI]";
        $parts[] = "Na końcu KAŻDEJ odpowiedzi dodaj blok podsumowania w formacie:";
        $parts[] = "<!--SUMMARY";
        $parts[] = '{';
        $parts[] = '  "waga": "93.3 kg" lub null,';
        $parts[] = '  "sen": "6h" lub null,';
        $parts[] = '  "energia": "6/10" lub null,';
        $parts[] = '  "glod": "7/10" lub null,';
        $parts[] = '  "bol": "6/10 nadgarstki" lub null,';
        $parts[] = '  "trening": "gumy + mikrosparing" lub null,';
        $parts[] = '  "posilki": "5 jajek, kawa" lub null,';
        $parts[] = '  "nastroj": "dobry" lub null,';
        $parts[] = '  "plan": "krótki plan na dziś/jutro" lub null,';
        $parts[] = '  "uwaga": "ważna obserwacja" lub null,';
        $parts[] = '  "meal_log": [{"name":"jogurt naturalny","amount":"350g","meal_type":"sniadanie","action":"add"}] lub null,';
        $parts[] = '  "training_log": [{"name":"rower","duration_min":20,"intensity":"umiarkowana","kcal_burned":180}] lub null,';
        $parts[] = '  "profile_update": {"height_cm":164,"age":55,"sex":"F"} lub null';
        $parts[] = '}';
        $parts[] = "SUMMARY-->";
        $parts[] = "Wypełniaj TYLKO pola o których była mowa. Resztę zostaw null.";
        $parts[] = "Blok SUMMARY to ukryty JSON - nie opisuj go w tekście odpowiedzi.";
        $parts[] = "WAŻNE: Gdy użytkownik wspomina że je/zjadł/zje coś, ZAWSZE wypełnij meal_log z konkretnymi produktami i ilościami.";
        $parts[] = "meal_type: sniadanie/lunch/obiad/kolacja/przekaska. Podawaj realistyczne ilości.";
        $parts[] = "meal_log action: 'add' (domyślne - nowy posiłek), 'replace' (korekta - zastąp istniejący wpis nowym, podaj old_name), 'remove' (usuń wpis, podaj name).";
        $parts[] = "Gdy użytkownik koryguje ilość/produkt, użyj action:'replace' z old_name (nazwa starego wpisu) i nowymi danymi. NIE dodawaj nowego wpisu obok starego!";
        $parts[] = "Przykład korekty: {\"name\":\"twaróg półtłusty\",\"amount\":\"350g\",\"meal_type\":\"sniadanie\",\"action\":\"replace\",\"old_name\":\"twaróg półtłusty\"}";
        $parts[] = "WAŻNE: Gdy użytkownik wspomina trening/ćwiczenia/aktywność fizyczną, ZAWSZE wypełnij training_log.";
        $parts[] = "Szacuj kcal_burned na podstawie: MET × waga_kg × czas_h. Waga użytkownika: " . ($projectState['current_weight_kg'] ?? 93) . " kg.";
        $parts[] = "Popularne MET: chodzenie=3.5, rower_umiarkowany=6.8, bieganie=9.8, siłownia=5.0, armwrestling_trening=4.5, pływanie=7.0, spacer=3.0.";
        $parts[] = "WAŻNE: Gdy użytkownik podaje wzrost, wiek lub płeć, ZAWSZE wypełnij profile_update. sex: 'M' (mężczyzna) lub 'F' (kobieta). Wypełniaj tylko zmienione pola.";

        return implode("\n", $parts);
    }

    /**
     * Pobiera ostatnie raw messages do kontekstu (max 10)
     * Tylko niestreszczone lub ostatnie z bieżącego bloku
     */
    public static function getRecentMessages(int $projectId): array
    {
        return Message::getRecent($projectId, 10);
    }
}
