# Quick Reference Card

## 🚀 Common Commands

```bash
# Capture API responses from tests
php artisan documentation:capture

# Capture and clear existing first
php artisan documentation:capture --clear

# Capture with detailed stats
php artisan documentation:capture --stats

# Generate documentation (uses captured + static)
php artisan documentation:generate

# Validate accuracy
php artisan documentation:validate

# Validate with strict mode (fail if < 95%)
php artisan documentation:validate --strict

# Validate with custom threshold
php artisan documentation:validate --strict --min-accuracy=90

# Generate validation report
php artisan documentation:validate --report
```

## ⚙️ Configuration

### Enable Capture (.env.local)

```env
DOC_CAPTURE_MODE=true
```

### Config File (config/api-documentation.php)

```php
'capture' => [
    'enabled' => env('DOC_CAPTURE_MODE', false),
    'storage_path' => base_path('.schemas/responses'),
],

'generation' => [
    'use_captured' => true,
    'merge_strategy' => 'captured_priority',
],
```

## 📂 File Locations

```
.schemas/responses/           ← Captured response files (commit to git!)
storage/api-docs/            ← Validation reports
storage/app/public/          ← Generated OpenAPI JSON
```

## 🎯 Workflow

### Daily Development

```bash
# 1. Write code + test
vim app/Http/Controllers/Api/UserController.php
vim tests/Feature/Api/UserControllerTest.php

# 2. Run tests (auto-captures)
composer test

# 3. Commit (including .schemas/)
git add .
git commit -m "Add user endpoint"
```

### Manual Capture

```bash
composer test  # or php artisan documentation:capture
php artisan documentation:generate
```

### Validation

```bash
php artisan documentation:validate
```

## 🔍 Checking Accuracy

```bash
$ php artisan documentation:validate

Overall Accuracy: 97%

┌───┬────────┬─────────────┬──────────┬────────┐
│   │ Method │ Route       │ Accuracy │ Issues │
├───┼────────┼─────────────┼──────────┼────────┤
│ ✓ │ GET    │ api/users   │ 100%     │ -      │
│ ✓ │ POST   │ api/users   │ 98%      │ -      │
└───┴────────┴─────────────┴──────────┴────────┘
```

## 🐛 Troubleshooting

| Problem | Solution |
|---------|----------|
| No captures created | Check `DOC_CAPTURE_MODE=true` |
| Low accuracy | Run `php artisan documentation:capture --clear` |
| Stale data | Re-run tests to update captures |
| Wrong schema | Check test assertions match actual API |

## 📊 Accuracy Targets

| Accuracy | Status | Action |
|----------|--------|--------|
| ≥ 95% | ✓ Excellent | No action needed |
| 80-94% | ⚠ Warning | Review captures |
| < 80% | ✗ Failed | Re-capture responses |

## 🔒 Security

Automatically sanitized:
- `password`
- `token`
- `api_key`
- `authorization`
- `access_token`
- `secret`

## 💡 Pro Tips

1. **Commit .schemas/** - It's your API's ground truth
2. **Run capture in CI** - Ensures captures stay current
3. **Use strict validation** - Catches accuracy drops
4. **Review schema diffs** - Catches unintended API changes

## 📚 Full Documentation

- Detailed guide: [RUNTIME_CAPTURE_GUIDE.md](RUNTIME_CAPTURE_GUIDE.md)
- Implementation: [IMPLEMENTATION_SUMMARY.md](IMPLEMENTATION_SUMMARY.md)
- README: [README.md](README.md)
