# Customer Journey — Choosing a Branch

> **Status: PLANNED.** This document describes **designed, not-yet-implemented** behaviour
> (plan approved 2026-08-26). The system has no concept of a branch today.
> Plan: [`docs/features/lokalizacje/`](../../app/docs/features/lokalizacje/README.md).

**For customers:** if your business has several branches, the customer picks one once — the way
you pick a store — and from then on the catalogue, availability and pickup all refer to that
location. If you have a single site, the customer sees no chooser at all and everything looks
exactly as it does today.

Applies to tenants with the `multi_location_stock` flag enabled. Extends the
[rental journey](customer-journey-rental.en.md); it does not replace it.

## Governing rule

**One order = one pickup branch.** A customer needing equipment from two sites places two orders.
This is enforced by the schema (`carts.location_id`), not by code discipline — it cannot be broken
by accident.

## Full path

```mermaid
flowchart TD
    START(["Customer lands on the site"])
    START --> CTX{Company has > 1 active branch?}

    CTX -- No --> AUTO["Primary branch set automatically\nSwitcher is NOT rendered"]
    AUTO --> CAT

    CTX -- Yes --> DEFAULT["Primary branch pre-selected\nSwitcher visible in the header"]
    DEFAULT --> CAT["/wypozyczalnia\nCatalogue"]

    CAT --> CARD["Equipment card\nAvailable at your branch: N units"]
    CARD --> AVAIL{Available here?}

    AVAIL -- No --> ELSEWHERE["Also available in: Gdansk (2 units)\nlink switches branch"]
    ELSEWHERE --> SWITCH

    AVAIL -- Yes --> PRODUCT["/uslugi/{service:slug}\nAvailability calendar for THIS branch"]
    PRODUCT --> DATES["Pick a date range"]
    DATES --> CART["Add to cart"]

    SWITCH["Switch branch"] --> CARTFULL{Cart non-empty?}
    CARTFULL -- No --> CAT
    CARTFULL -- Yes --> CONFIRM["Prompt: items belong to another branch.\nRecalculate the cart?"]
    CONFIRM --> CAT

    CART --> CHECKOUT["Checkout\nPickup address = selected branch"]
    CHECKOUT --> REVALIDATE{Still free\nat this branch?}
    REVALIDATE -- No --> TAKEN["Someone was faster —\nmessage, back to cart"]
    REVALIDATE -- Yes --> ORDER["Order placed\nHandover protocol carries the branch address"]
```

## What changes at each step

| Step | Change versus today |
|---|---|
| Landing | Primary branch pre-selected; header switcher (only when branches > 1) |
| Catalogue | Card shows availability **at the selected branch**, not the sum across all |
| Product page | Calendar and counter refer to the selected branch, plus "Also available in: Gdansk (2 units)" |
| Cart | Carries one pickup branch; switching with a non-empty cart is an **explicit prompt**, not an error |
| Checkout | Pickup address = branch address; validation rejects a branch outside the company |
| After purchase | Handover protocol and e-mails carry the branch address |

## Quantity: one unit per reservation

A **deliberate decision, not a technical limit**. The calendar exists so the customer picks a date
range every time; needing two units, they repeat the flow.

"2 units available" tells the customer **whether it is worth trying**, it is not a promise. The
decision is made when the order is placed — adding to the cart reserves nothing, so while the
customer deliberates someone may get ahead of them. **First to pay gets the equipment.**

## Returns

Equipment always goes back to **the branch that issued it**. The customer does not choose a return
point; the return address equals the pickup address and appears on the protocol.

## Deliberately out of scope

| Item | Why |
|---|---|
| Distance in kilometres ("Gdansk — 34 km") | The system does not know an anonymous visitor's position. Needs a separate decision (browser geolocation or a postcode field) |
| Returning to a different branch | Product owner's decision — keeps settlements and protocols simple |
| One order spanning two branches | One order = one handover protocol, one deposit, one pickup |
| Quantity selector | See above — the calendar is the repeatable-choice mechanism |
