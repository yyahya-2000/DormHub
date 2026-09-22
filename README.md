# HSE DormHub

A web system for the everyday administration of a student dormitory: guest passes,
announcements, maintenance requests and lost-and-found. Coursework, first year of the
master's programme in Systems and Software Engineering, HSE Faculty of Computer Science.

## Try it

| | |
|---|---|
| The system | https://dorm.yanalyahya.com |
| The API reference | https://dorm.yanalyahya.com/api/docs |

The stand carries generated data — two dormitories, their rooms, residents, and requests
in every state. Nothing on it belongs to a real person.

## Accounts

| Sign in as | Sees |
|---|---|
| `admin@example.test` | every dormitory; creates them, appoints the head of each |
| `warden@example.test` | one dormitory; appoints its staff, reads the visitor register |
| `manager01@example.test` | one dormitory; rooms, residencies, announcements, repairs, decides on guest requests |
| `security@example.test` | the security post; verifies a guest, records entry and exit |
| `student@example.test` | a resident: invites a guest, reports a defect, reads the feed |

**The password is one for all five — ask the author.** Sixty more resident accounts exist
(`resident00@example.test` and up) and share it.

Each account opens a different set of tabs, and the server refuses what the interface does
not offer: staff of one dormitory get 403 on another's data, at the API and not only in the
screen.

## The rest

- [Running it locally, testing, deploying](docs/running.md)
- [The API contract](backend/api/openapi.yaml) — 62 operations, the client is generated from it

Yanal Yakhya, 2026.
