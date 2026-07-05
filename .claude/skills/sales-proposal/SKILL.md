---
name: sales-proposal
description: Generate world-class sales materials for Registro — cold emails, phone scripts, demo outlines, proposals, objection handling. Uses SPIN/Challenger/Cialdini frameworks tailored to Polish SMB market.
argument-hint: "[typ: email|telefon|demo|propozycja|obiekcja] [segment: wypozyczalnia|detailing|uslugi] [kontekst opcjonalny]"
allowed-tools: Agent, Read, Write, Bash
effort: high
---

# /sales-proposal — Generator Materiałów Sprzedażowych

Zostałeś wywołany przez `/sales-proposal`. Twoim zadaniem jest wygenerowanie profesjonalnego materiału sprzedażowego dla Registro.

**Zadanie:** $ARGUMENTS

---

## KROK 1: Rozpoznaj typ i segment

Na podstawie argumentów określ:

| Co chce użytkownik | Typ materiału |
|-------------------|---------------|
| "zimny mail", "email do", "cold email" | Sekwencja cold email (E1-E5) lub pojedynczy email |
| "telefon", "skrypt", "cold call", "rozmowa" | Skrypt telefoniczny z obiekcjami |
| "demo", "prezentacja" | Struktura 30-min demo |
| "oferta", "propozycja", "po demo" | Proposal template wypełniony |
| "obiekcja", "za drogo", "nie mam czasu" | Konkretna odpowiedź na obiekcję |
| "pitch", "co to jest", "elevator" | One-liner / elevator pitch |
| "cały pakiet", "wszystko" | Pełen pakiet (email + telefon + demo outline) |

**Segment (jeśli nie podano — zapytaj lub domyśl z kontekstu):**
- `wypozyczalnia` — wypożyczalnia sprzętu budowlanego
- `detailing` — warsztat auto detailing
- `uslugi` — inne usługi lokalne

---

## KROK 2: Uruchom sales-proposal-specialist

Uruchom agenta z pełnym kontekstem:

```
Agent({
  subagent_type: "sales-proposal-specialist",
  prompt: `
    Wygeneruj [TYP MATERIAŁU] dla Registro skierowany do segmentu [SEGMENT].
    
    Kontekst od użytkownika: $ARGUMENTS
    
    Jeśli generujesz cold email:
    - Wariant A: [wypełnione placeholdery dla przykładowej firmy z segmentu]
    - Wariant B: alternatywny temat i angle
    
    Jeśli generujesz propozycję po demo:
    - Zapytaj o dane klienta jeśli nie podano (nazwa firmy, bóle z rozmowy, liczby)
    - Jeśli brak — generuj z przykładowymi liczbami z ICP
    
    Zawsze dołącz:
    - UWAGI DO PERSONALIZACJI (co zmienić przed wysłaniem)
    - TRIGGER do A/B testu (np. 2 wersje tematu emaila)
    - NEXT STEP (co zrobić z tym materiałem)
  `
})
```

---

## KROK 3: Output do użytkownika

Prezentuj output w sekcjach:

### Dla cold email:
```
--- TEMAT ---
[temat]

--- TREŚĆ ---
[email plain text]

--- PERSONALIZACJA ---
• [Firma]: zmień na nazwę firmy
• [region]: zmień na miasto/region
• [liczba]: sprawdź i dopasuj do segmentu

--- WARIANT B (testuj A/B) ---
Temat: [alternatywny temat]
```

### Dla skryptu telefonicznego:
```
--- OPENING ---
[skrypt słowo w słowo]

--- KWALIFIKACJA ---
[pytania SPIN]

--- BOOKING DEMO ---
[skrypt]

--- OBIEKCJE ---
[każda z pełną odpowiedzią]

--- REMINDERA ---
• Ton opadający, nie pytający
• Milcz po pytaniu implikacyjnym
• Nigdy "czy to dobry moment?"
```

### Dla propozycji po demo:
```
--- EMAIL (gotowy do wysłania) ---
Temat: Registro dla [Firma] — podsumowanie + następny krok
[pełna treść]

--- KIEDY WYSŁAĆ ---
Max 4h po demo. Nie po 24h.
```

---

## ZASADY KTÓRYCH NIGDY NIE ŁAM

1. Plain text cold email, max 125 słów, jedno CTA
2. Propozycja wyłącznie po odkryciu bólów klienta — nigdy jako pierwszy kontakt
3. Social proof = imię + miasto + branża + liczba (wszystkie 4)
4. Zawsze konkretny next step z datą
5. Cena pada tylko po ROI anchor — nigdy na początku
6. Jeśli użytkownik nie podał segmentu — wygeneruj dla wypożyczalni (segment A, największy)
