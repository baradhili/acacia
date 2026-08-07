# Contributing to Laravel ERP

Thank you for your interest in contributing to Laravel ERP! This document provides guidelines and instructions for contributing to this project.

## Getting Started

1. **Fork the repository** - Start by forking the repository to your GitHub account.

2. **Clone your fork** - Clone your fork locally:
   ```bash
   git clone https://github.com/YOUR_USERNAME/laravel-erp.git
   cd laravel-erp
   ```

3. **Add upstream remote** - Keep your fork synced with the main repository:
   ```bash
   git remote add upstream https://github.com/baradhili/laravel-erp.git
   ```

4. **Install dependencies** - Set up your development environment:
   ```bash
   composer install
   npm install
   cp .env.example .env
   php artisan key:generate
   ```

## Branching Strategy

- **`fixes`** - For bug fixes and minor improvements
- **`features`** - For new features
- Use descriptive branch names: `fix/issue-description` or `feature/feature-description`

## Development Workflow

### 1. Create a Branch

```bash
git checkout fixes  # or features
git pull upstream fixes
git checkout -b fix/your-fix-name
```

### 2. Make Your Changes

- Follow the existing code style and conventions
- Write clean, well-documented code
- Keep changes focused and atomic
- Test your changes thoroughly

### 3. Commit Your Changes

We use conventional commits. Your commit message should follow this format:

```
type(scope): description

[optional body]

[optional footer]
```

**Types:**
- `feat` - A new feature
- `fix` - A bug fix
- `docs` - Documentation only changes
- `style` - Code style changes (formatting, semicolons, etc)
- `refactor` - Code refactoring
- `test` - Adding or updating tests
- `chore` - Maintenance tasks

**Examples:**
```bash
git commit -m "fix(expenses): correct supplier reference in expense model"
git commit -m "feat(projects): add purchase order linking to projects"
git commit -m "docs: update README with new setup instructions"
```

### 4. Push and Create Pull Request

```bash
git push origin fix/your-fix-name
```

Then create a Pull Request on GitHub:

1. Navigate to your fork on GitHub
2. Click "Compare & pull request"
3. Ensure the base branch is `fixes` (or `features` for new features)
4. Fill in a clear title and description
5. Reference any related issues (e.g., "Fixes #123")
6. Submit the pull request

## Pull Request Guidelines

### Title Format
- Use the imperative mood: "Add feature" not "Added feature"
- Keep it concise (under 72 characters)
- Reference issue numbers when applicable

### Description Requirements
Every PR should include:

1. **Summary** - Brief description of what the PR does
2. **Related Issues** - Link to any related issues (e.g., "Closes #456")
3. **Testing** - Describe how you tested your changes
4. **Screenshots** (if UI changes) - Before/after screenshots if applicable

### PR Checklist
- [ ] Code follows the project's coding standards
- [ ] Self-review completed
- [ ] Tests added/updated for new functionality
- [ ] All tests pass locally
- [ ] Documentation updated (if applicable)
- [ ] No merge conflicts with base branch

## Testing

### Running Tests

```bash
# Run all tests
php artisan test

# Run with coverage
php artisan test --coverage

# Run specific test file
php artisan test --filter=ExpenseTest
```

### Writing Tests

- **Unit tests** - Test individual methods/classes
- **Feature tests** - Test user-facing functionality
- **Integration tests** - Test interactions between components

```php
// Example test structure
public function test_can_create_expense()
{
    $response = $this->post('/expenses', [
        'supplier_id' => $supplier->id,
        'category' => 'office_supplies',
        'amount' => 100.00,
        'expense_date' => now()->format('Y-m-d'),
    ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('expenses', [
        'supplier_id' => $supplier->id,
        'amount' => 100.00,
    ]);
}
```

### Test Coverage Requirements

- New features must include tests
- Bug fixes should include a test that fails without the fix
- Maintain or improve existing coverage
- Critical paths should have 80%+ coverage

## Code Standards

### PHP/Laravel
- Follow PSR-12 coding standards
- Use Laravel conventions and best practices
- Type hint where possible
- Use traits and interfaces appropriately
- Keep controllers thin, move logic to services

### Blade Templates
- Use semantic HTML
- Keep views clean and readable
- Use components for reusable UI elements
- Follow Tailwind CSS conventions

### Database
- Use migrations for all schema changes
- Use factories for test data
- Avoid raw SQL when Eloquent suffices
- Index foreign keys

## Security

- **Never commit secrets** - Use `.env.example` and environment variables
- **Sanitize input** - Validate and escape all user input
- **Use authorization** - Check permissions before actions
- **Report vulnerabilities** - Email security issues to maintainers

## Questions?

- **Issues** - Open a GitHub issue for bugs or feature requests
- **Discussions** - Use GitHub Discussions for questions
- **Email** - For security issues, contact maintainers directly

## License

By contributing, you agree that your contributions will be licensed under the MIT License.
