# Katalogi do `catalog:import`

Pliki w formacie opisanym w `app/docs/features/catalog-json-importer.md` (schema v1).

## `demo-sprzet-budowlany.json`

50 urządzeń do wynajmu dla tenanta demo z branży `equipment_rental`, w 9 kategoriach:
agregaty prądotwórcze (10), nagrzewnice olejowe (7), gazowe (5), elektryczne (5), osuszacze
przemysłowe (8), agregaty tynkarskie (5), świdry i wiertnice glebowe (4), zagęszczarki płytowe (3),
pompy do wody brudnej (3).

```bash
docker compose exec -T app php artisan catalog:import <org-slug> database/data/catalogs/demo-sprzet-budowlany.json --image-delay=1500
docker compose exec -T app php artisan catalog:import-revert <batch> --reset-auto-increment   # cofnięcie
```

Tenant musi mieć dokładnie jeden aktywny oddział (produkty używają `stock.quantity`).
Import pobiera 28 zdjęć z Wikimedia Commons — ok. 1 minuta; Commons ogranicza tempo zapytań,
dlatego `--image-delay=1500`.

**Co jest prawdziwe, a co nie** — to dane demonstracyjne, nie cennik:

- **Modele i specyfikacje** — realne urządzenia; parametry zebrane ze stron producentów i
  dystrybutorów (lista źródeł nie jest częścią pliku). Kilka pozycji ma niepełną specyfikację.
- **Ceny brutto i kaucje** — w większości **szacowane** na podstawie polskich wypożyczalni dla
  podobnej klasy sprzętu; tylko część stawek dobowych osuszaczy i nagrzewnic olejowych pochodzi
  z opublikowanych cenników. Tydzień ≈ 4,5 × doba, stawka od 14. doby ≈ cena miesięczna / 30.
- **Opisy** — napisane od zera, nie kopiowane z materiałów producentów.
- **Zdjęcia** — **poglądowe**, z Wikimedia Commons na licencjach CC0 / CC BY / CC BY-SA /
  domena publiczna. Pokazują urządzenie tego typu, nie konkretny model (wyjątek: Wacker Neuson
  DPU 6555). Autor i licencja są w polach `credit`/`license`/`source_page` każdego zdjęcia oraz
  w widocznym podpisie na końcu `body_html` — **nie usuwaj tego podpisu**, CC BY / CC BY-SA
  wymagają uznania autorstwa. Nagrzewnice gazowe nie mają wolnego zdjęcia własnego typu
  i pokazują nagrzewnicę nadmuchową; przed pokazem klientowi warto podmienić zdjęcia na własne
  (`{"path": "images/..."}` obok pliku JSON).
