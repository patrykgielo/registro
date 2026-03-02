# Planning Rules - CRITICAL

## NOWE PLANY = NOWE PLIKI

**NIGDY nie nadpisuj istniejącego planu nowym zadaniem!**

### Zasady:

1. **Jeden plan = jeden plik** - każde nowe zadanie wymaga NOWEGO pliku planu
2. **Stare plany zachowuj** - mogą być potrzebne jako referencja
3. **Nazwy planów** - używaj opisowych nazw lub timestamp

### Workflow:

```bash
# Sprawdź czy istnieje plan
ls ~/.claude/plans/

# Jeśli użytkownik mówi "nowy plan" lub to jest INNE zadanie:
# → Stwórz NOWY plik planu, NIE nadpisuj starego!

# Przykład:
~/.claude/plans/featured-image-tiles.md      # Plan 1 (zachowany)
~/.claude/plans/typography-fix-services.md   # Plan 2 (nowy)
```

### Kiedy nadpisać vs stworzyć nowy:

| Sytuacja | Akcja |
|----------|-------|
| Kontynuacja tego samego zadania | Edytuj istniejący plan |
| Nowe zadanie (inne niż poprzednie) | **NOWY PLIK PLANU** |
| Użytkownik mówi "nowy plan" | **NOWY PLIK PLANU** |
| Plan ukończony, nowe zadanie | **NOWY PLIK PLANU** |

### Incident 2026-01-25

Nadpisano plan "Featured Image w Kafelkach CMS" planem "Typography Fix" zamiast stworzyć nowy plik.
