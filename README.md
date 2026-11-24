# Escalation — Approval Flow Plugin for GLPI

The **Escalation** plugin adds structured, multi-step approval flows to GLPI tickets. When a ticket is opened under a configured ITIL category, the plugin automatically activates its linked flow, resolves the approvers, and creates GLPI validations — all without manual intervention.

---

## Features

- **Multi-step flows** — each flow defines ordered steps that execute sequentially; a step only starts after the previous one is fully resolved
- **Two step types**
  - **People** — validations are sent to specific users and/or to ticket actors (requesters, assigned, observers)
  - **Hierarchy** — validations are resolved dynamically by traversing the requester's supervisor chain
- **Dynamic and fixed hierarchy resolution**
  - *Dynamic*: finds the immediate supervisor of each requester (one level up)
  - *Fixed level*: climbs the supervisor chain until reaching the configured organisational level (e.g. always escalate to the Director)
- **Per-step quorum** (People steps) — `all`, `majority` (> 50 %), or `min1` (first approval advances the flow)
- **Approval templates** — reusable text templates with attached documents sent as the validation request content
- **ITIL category binding** — each flow is linked to one or more ITIL categories; a ticket's category determines which flow (if any) is triggered
- **Hierarchical user levels** — levels are mapped to either **user categories** or **groups**, configured globally under Setup → Configuration → Escalation
- **Approval tab on tickets** — dedicated tab showing each step's status, approvers, and timestamps

---

## Requirements

| Dependency | Version |
|---|---|
| GLPI | ≥ 10.0.0 |
| PHP | ≥ 7.4 |

---

## Installation

1. Download the plugin archive and extract it to `<glpi>/marketplace/escalation/`
2. In GLPI go to **Setup → Plugins**, find *Escalation* and click **Install**, then **Enable**
3. Configure the hierarchy type under **Setup → Configuration → Escalation**

---

## Configuration

### 1 — Hierarchy type

Go to **Setup → Configuration → Escalation**.

| Setting | Description |
|---|---|
| User category | Levels are determined by the user's profile category (`UserCategory`) |
| Group | Levels are determined by the user's group membership |

Add a level mapping for each organisational level (level 1 = highest, level N = lowest).

| Setting | Description |
|---|---|
| Manage ticket status | *(default: Yes)* Ticket stays **Pending** while any step awaits approval, goes to **New** once every step is approved, and is **Solved** (cancelled) when a step is refused or times out |
| Solution type on refusal | Solution type recorded on the cancelling solution (e.g. *Cancelado*) |

**Business rules:** with status management on, the plugin coexists with ticket rules that set the status from the approval status (e.g. *approval waiting → Pending*, *approval accepted → New*, *approval refused → Solved*). While a step is awaiting approval the global approval stays *Waiting*, so such rules never release the ticket early. A ticket solved by a rule without a solution still gets the cancelling solution. Approvals generated on solution never move a solved/closed ticket back (rule status changes are discarded on those updates). Native approvals created by rules in the same ticket are honoured: the ticket stays Pending until they are answered, and a refusal cancels it.

In a fixed-level **Hierarchy** step, a requester who already holds the required level (or a higher one) is not asked to approve their own ticket: the step is approved automatically for them.

### 2 — Approval flows

Go to **Administration → Escalation → Flows**.

- Create a flow, assign one or more ITIL categories and (optionally) a global auto-rejection timeout in days
- Inside the flow, add steps in order using the inline form

**Step fields:**

| Field | Description |
|---|---|
| Name | Label shown in the approval tab |
| Type | *People* or *Hierarchy* |
| Level / Dynamic | *(Hierarchy only)* Fixed target level, or **Dynamic** (supervisor of each requester) |
| Quorum | *(People only)* `all` / Majority / Minimum 1 |
| Generate on | *Immediately* on ticket creation, or when the ticket is set to **Solved** |
| Timeout (days) | Per-step auto-rejection; 0 = no timeout |
| Approval template | Optional template with pre-filled content and attached documents |

For **People** steps, add specific approvers after creating the step, and optionally include requesters / assigned / observers via the checkboxes.

### 3 — Approval templates

Go to **Administration → Escalation → Templates**.

A template contains rich text (shown as the validation request content) and can have documents attached. Templates are reusable across multiple steps and flows.

---

## How approval flows work

```
Ticket created
    │
    └─ ITIL category matches a flow?
           │ Yes
           ▼
       Flow starts (TicketFlow created)
           │
           └─ Step 1 activated
                  │
                  ├─ Hierarchy / dynamic  →  finds supervisor of each requester
                  ├─ Hierarchy / fixed    →  climbs supervisor chain to target level
                  └─ People              →  specific users + configured actor groups
                         │
                         ▼
                   TicketValidation created for each resolved approver
                         │
                  Approver(s) respond
                         │
                         ├─ Quorum reached?  Yes  →  Step marked Approved → next step
                         └─ Any refusal?     Yes  →  Flow marked Rejected
                                │
                          All steps approved
                                │
                                ▼
                         Flow marked Approved
                         Followup added to ticket
```

---


## Authors

- Ampris
- Matheus Schmidt

## License

GPL v2+
