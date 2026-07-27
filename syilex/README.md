# syilex (backend)

Backend Laravel untuk POSIP.

## Dokumentasi

**→ [`../docs/README.md`](../docs/README.md)**

| Topik | Path |
|-------|------|
| Architecture | [`../docs/domain/architecture.md`](../docs/domain/architecture.md) |
| API | [`../docs/domain/api.md`](../docs/domain/api.md) |
| Onboarding | [`../docs/00-start/onboarding.md`](../docs/00-start/onboarding.md) |
| Deploy | [`../docs/ops/`](../docs/ops/) |
| Serial / Promo | [`../docs/domain/`](../docs/domain/) |
| AI agent | [`../docs/ai/README.md`](../docs/ai/README.md) |

## Local setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

Detail: [`../docs/00-start/onboarding.md`](../docs/00-start/onboarding.md).
