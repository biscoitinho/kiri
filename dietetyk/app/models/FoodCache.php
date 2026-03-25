<?php

class FoodCache
{
    public static function search(string $query, int $limit = 5): array
    {
        $db = Database::get();
        $queryLower = strtolower(trim($query));

        // Usuń polskie końcówki fleksyjne (prosty stemming)
        $words = preg_split('/\s+/', $queryLower);
        $conditions = [];
        $params = [];

        // Słowa do pominięcia (stop words + ilości)
        $stopWords = ['z', 'na', 'w', 'i', 'do', 'ze', 'bez', 'po', 'od', 'dla', 'nie', 'ok', 'około', 'gram', 'gramów', 'szt', 'ml', 'kg', 'g'];

        foreach ($words as $i => $word) {
            if (in_array($word, $stopWords) || strlen($word) < 2) continue;
            if (is_numeric($word)) continue; // pomiń liczby ("350")

            // Stemming PL: usuwaj typowe końcówki odmian
            $stem = $word;
            // Przymiotniki: -nego, -iego, -owej, -owym → obetnij końcówkę
            $stem = preg_replace('/(owego|owej|owym|nego|iego|nych|nych|emu|ego|nej|nym|ych)$/u', '', $stem) ?: $stem;
            // Rzeczowniki: -ów, -ach, -ami, -om, -em, -ie, -ek, -ka, -ko, -ki, -u, -ę, -ą
            if (strlen($stem) === strlen($word)) {
                $stem = preg_replace('/(ów|ach|ami|om|em|ie|ek|ka|ko|ki|ą|ę|u)$/u', '', $stem) ?: $stem;
            }
            // Bezpieczna minimalna długość: 3 znaki
            if (strlen($stem) < 3) {
                $stem = strlen($word) > 4 ? substr($word, 0, -2) : $word;
            }

            $conditions[] = "name_lower LIKE :w{$i}";
            $params[":w{$i}"] = "%{$stem}%";
        }

        if (empty($conditions)) {
            return [];
        }

        $where = implode(' AND ', $conditions);
        $stmt = $db->prepare("
            SELECT * FROM food_cache
            WHERE {$where}
            ORDER BY search_count DESC, name_lower ASC
            LIMIT :limit
        ");

        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $results = $stmt->fetchAll();

        // Fallback: jeśli brak wyników, szukaj po krótszych prefixach (min 3 znaki)
        if (empty($results)) {
            $prefixConditions = [];
            $prefixParams = [];
            foreach ($words as $i => $word) {
                if (in_array($word, $stopWords) || strlen($word) < 3) continue;
                if (is_numeric($word)) continue;
                $prefix = substr($word, 0, max(3, strlen($word) - 3));
                $prefixConditions[] = "name_lower LIKE :p{$i}";
                $prefixParams[":p{$i}"] = "%{$prefix}%";
            }
            if (!empty($prefixConditions)) {
                $prefixWhere = implode(' AND ', $prefixConditions);
                $stmt2 = $db->prepare("SELECT * FROM food_cache WHERE {$prefixWhere} ORDER BY search_count DESC LIMIT :limit");
                foreach ($prefixParams as $key => $val) {
                    $stmt2->bindValue($key, $val);
                }
                $stmt2->bindValue(':limit', $limit, PDO::PARAM_INT);
                $stmt2->execute();
                $results = $stmt2->fetchAll();
            }
        }

        // Zwiększ counter wyszukiwań
        foreach ($results as $row) {
            $db->prepare("UPDATE food_cache SET search_count = search_count + 1 WHERE id = ?")
                ->execute([$row['id']]);
        }

        return $results;
    }

    public static function addFromAI(string $name, array $nutrition, string $brand = ''): int
    {
        $db = Database::get();
        $nameLower = strtolower(trim($name));

        // Sprawdź czy już istnieje
        $existing = $db->prepare("SELECT id FROM food_cache WHERE name_lower = ?");
        $existing->execute([$nameLower]);
        if ($existing->fetch()) {
            return 0; // już jest
        }

        $stmt = $db->prepare("
            INSERT INTO food_cache (name, name_lower, brand, kcal_100g, protein_100g, carbs_100g, fat_100g, typical_portion_g, source)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'ai_cached')
        ");
        $stmt->execute([
            trim($name),
            $nameLower,
            $brand,
            (int) ($nutrition['kcal'] ?? 0),
            (float) ($nutrition['protein'] ?? 0),
            (float) ($nutrition['carbs'] ?? 0),
            (float) ($nutrition['fat'] ?? 0),
            (int) ($nutrition['portion'] ?? 100),
        ]);

        return (int) $db->lastInsertId();
    }

    public static function count(): int
    {
        return (int) Database::get()->query("SELECT COUNT(*) FROM food_cache")->fetchColumn();
    }

    public static function isSeeded(): bool
    {
        $count = (int) Database::get()
            ->query("SELECT COUNT(*) FROM food_cache WHERE source = 'seed'")
            ->fetchColumn();
        return $count > 0;
    }

    public static function seed(): void
    {
        if (self::isSeeded()) return;

        $products = self::getSeedData();
        $db = Database::get();

        $stmt = $db->prepare("
            INSERT INTO food_cache (name, name_lower, brand, kcal_100g, protein_100g, carbs_100g, fat_100g, fiber_100g, typical_portion_g, source)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'seed')
        ");

        foreach ($products as $p) {
            $stmt->execute([
                $p[0],                          // name
                strtolower($p[0]),           // name_lower
                $p[1] ?? '',                    // brand
                $p[2],                          // kcal
                $p[3],                          // protein
                $p[4],                          // carbs
                $p[5],                          // fat
                $p[6] ?? 0,                     // fiber
                $p[7] ?? 100,                   // portion
            ]);
        }
    }

    private static function getSeedData(): array
    {
        // [name, brand, kcal/100g, protein, carbs, fat, fiber, typical_portion_g]
        return [
            // === NABIAŁ ===
            ['Mleko 2%', '', 50, 3.4, 4.9, 2.0, 0, 250],
            ['Mleko 3.2%', '', 60, 3.2, 4.7, 3.2, 0, 250],
            ['Mleko 0.5%', '', 35, 3.5, 5.0, 0.5, 0, 250],
            ['Jogurt naturalny', '', 61, 4.0, 4.7, 3.0, 0, 150],
            ['Jogurt grecki', '', 97, 9.0, 3.6, 5.0, 0, 150],
            ['Kefir', '', 56, 3.3, 4.0, 2.5, 0, 200],
            ['Twaróg półtłusty', '', 131, 18.0, 3.3, 4.5, 0, 200],
            ['Twaróg chudy', '', 98, 18.0, 4.0, 0.5, 0, 200],
            ['Ser żółty gouda', '', 356, 25.0, 0.5, 28.0, 0, 30],
            ['Ser żółty edamski', '', 330, 25.0, 0.5, 25.0, 0, 30],
            ['Ser mozzarella', '', 280, 22.0, 2.0, 20.0, 0, 125],
            ['Ser feta', '', 264, 14.0, 4.0, 21.0, 0, 50],
            ['Ser camembert', '', 300, 20.0, 0.5, 24.0, 0, 30],
            ['Ser cottage', '', 98, 11.0, 3.4, 4.3, 0, 200],
            ['Serek wiejski', '', 105, 12.0, 3.5, 4.5, 0, 200],
            ['Śmietana 18%', '', 186, 2.7, 3.4, 18.0, 0, 30],
            ['Śmietana 12%', '', 131, 2.9, 3.6, 12.0, 0, 30],
            ['Masło 82%', '', 740, 0.7, 0.6, 82.0, 0, 10],

            // === JAJKA ===
            ['Jajko kurze', '', 155, 13.0, 1.1, 11.0, 0, 60],
            ['Jajko na twardo', '', 155, 13.0, 1.1, 11.0, 0, 60],
            ['Jajko sadzone', '', 196, 14.0, 0.8, 15.0, 0, 60],
            ['Jajko na miękko', '', 155, 13.0, 1.1, 11.0, 0, 60],
            ['Jajecznica (2 jajka)', '', 196, 14.0, 1.0, 15.0, 0, 150],

            // === MIĘSO I WĘDLINY ===
            ['Pierś z kurczaka', '', 110, 23.0, 0, 1.3, 0, 150],
            ['Udko z kurczaka', '', 174, 18.0, 0, 11.0, 0, 150],
            ['Pierś z indyka', '', 104, 24.0, 0, 0.7, 0, 150],
            ['Schab wieprzowy', '', 155, 21.0, 0, 7.5, 0, 150],
            ['Karkówka wieprzowa', '', 236, 17.0, 0, 18.5, 0, 150],
            ['Polędwica wołowa', '', 143, 22.0, 0, 5.8, 0, 150],
            ['Wołowina mielona', '', 254, 17.0, 0, 20.0, 0, 150],
            ['Kiełbasa śląska', '', 259, 14.0, 1.0, 22.0, 0, 100],
            ['Kiełbasa krakowska', '', 263, 16.0, 1.0, 22.0, 0, 50],
            ['Kiełbasa podwawelska', '', 220, 14.0, 2.0, 17.0, 0, 50],
            ['Szynka wędzona', '', 145, 20.0, 1.0, 7.0, 0, 50],
            ['Szynka konserwowa', '', 107, 17.0, 1.5, 3.5, 0, 50],
            ['Kabanosy', '', 380, 22.0, 2.0, 32.0, 0, 25],
            ['Parówki', '', 222, 10.0, 3.0, 19.0, 0, 50],
            ['Salami', '', 378, 22.0, 1.5, 32.0, 0, 30],
            ['Boczek wędzony', '', 458, 14.0, 0, 45.0, 0, 30],

            // === RYBY ===
            ['Łosoś', '', 208, 20.0, 0, 13.0, 0, 150],
            ['Tuńczyk (puszka w wodzie)', '', 108, 25.0, 0, 0.6, 0, 150],
            ['Tuńczyk (puszka w oleju)', '', 198, 24.0, 0, 11.0, 0, 150],
            ['Dorsz', '', 82, 18.0, 0, 0.7, 0, 150],
            ['Makrela wędzona', '', 305, 19.0, 0, 25.0, 0, 100],
            ['Śledź w oleju', '', 262, 14.0, 3.0, 22.0, 0, 100],
            ['Tilapia', '', 96, 20.0, 0, 1.7, 0, 150],

            // === PIECZYWO ===
            ['Chleb pszenny', '', 265, 8.5, 50.0, 3.0, 3.0, 40],
            ['Chleb żytni', '', 250, 8.0, 48.0, 3.0, 6.0, 40],
            ['Chleb razowy', '', 240, 9.0, 44.0, 3.0, 7.0, 40],
            ['Bułka pszenna', '', 275, 9.0, 52.0, 3.0, 2.5, 50],
            ['Bułka grahamka', '', 255, 9.5, 46.0, 3.0, 5.0, 60],
            ['Bagietka', '', 270, 9.0, 52.0, 2.5, 2.5, 60],
            ['Chleb tostowy', '', 255, 8.0, 48.0, 3.5, 2.5, 25],
            ['Tortilla pszenna', '', 310, 8.0, 52.0, 8.0, 2.0, 60],

            // === KASZE, RYŻE, MAKARONY ===
            ['Ryż biały (suchy)', '', 360, 7.0, 79.0, 0.6, 0.4, 80],
            ['Ryż brązowy (suchy)', '', 355, 7.5, 76.0, 2.7, 3.5, 80],
            ['Ryż basmati (suchy)', '', 350, 7.5, 78.0, 0.6, 0.4, 80],
            ['Kasza gryczana (sucha)', '', 343, 13.0, 72.0, 3.0, 10.0, 80],
            ['Kasza jaglana (sucha)', '', 350, 11.0, 70.0, 3.5, 3.0, 80],
            ['Kasza jęczmienna (sucha)', '', 336, 10.0, 72.0, 1.3, 10.0, 80],
            ['Kasza kuskus (sucha)', '', 376, 13.0, 77.0, 0.6, 2.0, 80],
            ['Makaron (suchy)', '', 350, 12.0, 71.0, 1.5, 3.0, 80],
            ['Makaron pełnoziarnisty (suchy)', '', 340, 13.0, 66.0, 2.5, 7.0, 80],
            ['Płatki owsiane', '', 367, 14.0, 60.0, 7.0, 10.0, 50],
            ['Płatki kukurydziane', '', 370, 7.0, 84.0, 1.0, 1.5, 30],

            // === WARZYWA ===
            ['Ziemniak', '', 77, 2.0, 17.0, 0.1, 2.2, 150],
            ['Batata (słodki ziemniak)', '', 86, 1.6, 20.0, 0.1, 3.0, 150],
            ['Pomidor', '', 18, 0.9, 3.9, 0.2, 1.2, 150],
            ['Ogórek', '', 12, 0.6, 2.2, 0.1, 0.5, 100],
            ['Papryka czerwona', '', 26, 1.0, 4.4, 0.3, 2.1, 150],
            ['Papryka zielona', '', 20, 0.9, 3.5, 0.2, 1.7, 150],
            ['Marchewka', '', 41, 0.9, 10.0, 0.2, 2.8, 100],
            ['Cebula', '', 40, 1.1, 9.3, 0.1, 1.7, 80],
            ['Czosnek', '', 149, 6.4, 33.0, 0.5, 2.1, 5],
            ['Brokuły', '', 34, 2.8, 7.0, 0.4, 2.6, 150],
            ['Kalafior', '', 25, 1.9, 5.0, 0.3, 2.0, 150],
            ['Szpinak', '', 23, 2.9, 3.6, 0.4, 2.2, 100],
            ['Sałata', '', 15, 1.4, 2.9, 0.2, 1.3, 100],
            ['Kapusta biała', '', 25, 1.3, 5.8, 0.1, 2.5, 150],
            ['Kapusta kiszona', '', 19, 0.9, 4.3, 0.1, 2.9, 150],
            ['Burak', '', 43, 1.6, 10.0, 0.2, 2.8, 100],
            ['Dynia', '', 26, 1.0, 6.5, 0.1, 0.5, 150],
            ['Cukinia', '', 17, 1.2, 3.1, 0.3, 1.0, 150],
            ['Bakłażan', '', 25, 1.0, 6.0, 0.2, 3.0, 150],
            ['Groszek zielony', '', 81, 5.4, 14.0, 0.4, 5.0, 80],
            ['Kukurydza (puszka)', '', 82, 2.5, 16.0, 1.2, 2.0, 100],
            ['Fasola biała (puszka)', '', 110, 7.0, 20.0, 0.5, 6.0, 100],
            ['Ciecierzyca (puszka)', '', 120, 7.0, 18.0, 2.5, 5.0, 100],
            ['Soczewica czerwona (sucha)', '', 340, 25.0, 60.0, 1.0, 11.0, 80],
            ['Pieczarki', '', 22, 3.1, 3.3, 0.3, 1.0, 100],
            ['Awokado', '', 160, 2.0, 9.0, 15.0, 7.0, 80],

            // === OWOCE ===
            ['Jabłko', '', 52, 0.3, 14.0, 0.2, 2.4, 180],
            ['Banan', '', 89, 1.1, 23.0, 0.3, 2.6, 120],
            ['Pomarańcza', '', 47, 0.9, 12.0, 0.1, 2.4, 180],
            ['Mandarynka', '', 53, 0.8, 13.0, 0.3, 1.8, 70],
            ['Gruszka', '', 57, 0.4, 15.0, 0.1, 3.1, 180],
            ['Kiwi', '', 61, 1.1, 15.0, 0.5, 3.0, 75],
            ['Truskawki', '', 33, 0.7, 8.0, 0.3, 2.0, 150],
            ['Maliny', '', 52, 1.2, 12.0, 0.7, 6.5, 100],
            ['Borówki', '', 57, 0.7, 14.0, 0.3, 2.4, 100],
            ['Winogrona', '', 69, 0.7, 18.0, 0.2, 0.9, 150],
            ['Arbuz', '', 30, 0.6, 8.0, 0.2, 0.4, 250],
            ['Śliwka', '', 46, 0.7, 11.0, 0.3, 1.4, 60],
            ['Brzoskwinia', '', 39, 0.9, 10.0, 0.3, 1.5, 150],
            ['Ananas', '', 50, 0.5, 13.0, 0.1, 1.4, 150],
            ['Mango', '', 60, 0.8, 15.0, 0.4, 1.6, 150],

            // === ORZECHY I NASIONA ===
            ['Orzechy włoskie', '', 654, 15.0, 14.0, 65.0, 7.0, 30],
            ['Orzechy laskowe', '', 628, 15.0, 17.0, 61.0, 10.0, 30],
            ['Migdały', '', 579, 21.0, 22.0, 50.0, 12.0, 30],
            ['Orzechy ziemne', '', 567, 26.0, 16.0, 49.0, 8.5, 30],
            ['Orzechy nerkowca', '', 553, 18.0, 30.0, 44.0, 3.3, 30],
            ['Masło orzechowe', '', 588, 25.0, 20.0, 50.0, 6.0, 20],
            ['Pestki dyni', '', 559, 30.0, 11.0, 49.0, 6.0, 20],
            ['Pestki słonecznika', '', 584, 21.0, 20.0, 51.0, 9.0, 20],
            ['Siemię lniane', '', 534, 18.0, 29.0, 42.0, 27.0, 15],
            ['Nasiona chia', '', 486, 17.0, 42.0, 31.0, 34.0, 15],

            // === TŁUSZCZE I OLEJE ===
            ['Oliwa z oliwek', '', 884, 0, 0, 100.0, 0, 10],
            ['Olej rzepakowy', '', 884, 0, 0, 100.0, 0, 10],
            ['Olej kokosowy', '', 862, 0, 0, 100.0, 0, 10],

            // === NAPOJE ===
            ['Kawa czarna (bez cukru)', '', 2, 0.3, 0, 0, 0, 250],
            ['Kawa z mlekiem', '', 20, 1.5, 2.0, 0.8, 0, 250],
            ['Herbata czarna (bez cukru)', '', 1, 0, 0.3, 0, 0, 250],
            ['Sok pomarańczowy', '', 45, 0.7, 10.0, 0.2, 0.2, 250],
            ['Sok jabłkowy', '', 46, 0.1, 11.0, 0.1, 0.2, 250],
            ['Cola', '', 42, 0, 11.0, 0, 0, 330],
            ['Cola zero', '', 0, 0, 0, 0, 0, 330],
            ['Piwo (5%)', '', 43, 0.5, 3.6, 0, 0, 500],
            ['Wino czerwone', '', 85, 0.1, 2.6, 0, 0, 150],
            ['Wódka (40%)', '', 231, 0, 0, 0, 0, 50],

            // === SŁODYCZE I PRZEKĄSKI ===
            ['Czekolada mleczna', '', 535, 7.0, 59.0, 30.0, 2.0, 25],
            ['Czekolada gorzka 70%', '', 598, 8.0, 46.0, 43.0, 11.0, 25],
            ['Cukier biały', '', 400, 0, 100.0, 0, 0, 10],
            ['Miód', '', 304, 0.3, 82.0, 0, 0, 20],
            ['Dżem', '', 250, 0.5, 60.0, 0.1, 1.0, 25],
            ['Nutella', '', 539, 6.3, 57.5, 30.9, 3.4, 20],
            ['Baton proteinowy', '', 350, 30.0, 35.0, 10.0, 5.0, 60],
            ['Chipsy ziemniaczane', '', 536, 6.5, 53.0, 33.0, 4.0, 30],
            ['Paluszki (precelki)', '', 380, 10.0, 74.0, 4.0, 3.0, 30],
            ['Wafel ryżowy', '', 387, 8.0, 82.0, 3.0, 1.0, 10],
            ['Baton Snickers', '', 480, 8.0, 60.0, 23.0, 1.5, 50],
            ['Lody waniliowe', '', 207, 3.5, 24.0, 11.0, 0.5, 100],
            ['Pączek', '', 375, 6.0, 48.0, 18.0, 1.5, 65],
            ['Croissant', '', 406, 8.0, 46.0, 21.0, 2.0, 60],

            // === DANIA GOTOWE / TYPOWE POLSKIE ===
            ['Pierogi ruskie (gotowane)', '', 195, 6.0, 30.0, 5.0, 1.5, 250],
            ['Pierogi z mięsem', '', 220, 10.0, 28.0, 7.0, 1.0, 250],
            ['Bigos', '', 85, 5.0, 5.0, 5.0, 2.0, 300],
            ['Żurek', '', 45, 3.0, 5.0, 1.5, 0.5, 350],
            ['Barszcz czerwony', '', 25, 0.8, 5.0, 0.2, 1.0, 300],
            ['Rosół z makaronem', '', 40, 3.0, 4.0, 1.5, 0.3, 350],
            ['Kotlet schabowy (panierowany)', '', 225, 18.0, 10.0, 12.0, 0.5, 180],
            ['Kotlet mielony', '', 210, 15.0, 8.0, 13.0, 0.5, 120],
            ['Placki ziemniaczane', '', 150, 3.0, 22.0, 5.5, 1.5, 200],
            ['Naleśniki z serem', '', 195, 8.0, 28.0, 6.0, 0.5, 200],
            ['Kopytka', '', 170, 4.5, 33.0, 2.5, 1.2, 200],
            ['Gołąbki', '', 110, 7.0, 10.0, 4.5, 1.0, 250],
            ['Gulasz wołowy', '', 120, 12.0, 5.0, 6.0, 1.0, 300],
            ['Zupa pomidorowa z ryżem', '', 55, 1.5, 10.0, 1.0, 0.5, 350],
            ['Zupa ogórkowa', '', 35, 1.5, 5.0, 1.0, 0.5, 350],
            ['Zupa pieczarkowa', '', 45, 2.0, 5.0, 2.0, 0.5, 350],
            ['Leczo', '', 55, 2.0, 7.0, 2.0, 1.5, 300],

            // === ŚNIADANIOWE ===
            ['Owsianka na mleku', '', 90, 3.5, 14.0, 2.5, 1.5, 300],
            ['Musli z mlekiem', '', 110, 3.0, 18.0, 3.0, 2.0, 250],
            ['Granola', '', 450, 10.0, 62.0, 18.0, 7.0, 50],
            ['Kanapka z szynką', '', 230, 11.0, 28.0, 8.0, 2.0, 100],
            ['Kanapka z serem', '', 280, 12.0, 27.0, 14.0, 1.5, 100],
            ['Tost z masłem', '', 310, 8.0, 48.0, 10.0, 2.0, 50],

            // === SOSY I DODATKI ===
            ['Ketchup', '', 112, 1.8, 26.0, 0.2, 0.3, 15],
            ['Majonez', '', 680, 1.0, 1.0, 75.0, 0, 15],
            ['Musztarda', '', 66, 4.4, 5.0, 3.3, 3.3, 10],
            ['Sos sojowy', '', 53, 8.0, 5.0, 0.1, 0.8, 15],
        ];
    }
}
