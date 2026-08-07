# Documentation Site Setup

This guide explains how to set up and maintain a documentation site using GitHub Pages.

## Overview

GitHub Pages provides free hosting for static documentation sites directly from your GitHub repository.

## Option 1: GitHub Pages with Docs Folder

### Step 1: Enable GitHub Pages

1. Navigate to your repository on GitHub
2. Go to **Settings** > **Pages**
3. Under **Source**, select:
   - **Branch**: `phase-6` (or your main branch)
   - **Folder**: `/docs`
4. Click **Save**

### Step 2: Create Documentation Files

Place your Markdown documentation files in the `docs/` folder:

```
docs/
├── index.md              # Home page
├── getting-started.md    # Installation guide
├── deployment/            # Deployment documentation
│   ├── index.md
│   ├── forge.md
│   ├── sail.md
│   └── docker.md
├── runbooks/              # Operational runbooks
│   ├── index.md
│   └── backup-restore.md
└── api/                  # API documentation
    └── index.md
```

### Step 3: Add Navigation

Create a `_sidebar.md` file (if using a docs theme):

```markdown
# Navigation

- [Home](/)
- [Getting Started](/getting-started)
- [Deployment](/deployment/)
- [Runbooks](/runbooks/)
```

## Option 2: Using Jekyll

GitHub Pages automatically processes Jekyll sites.

### Create `_config.yml`

```yaml
title: PSA Documentation
description: Professional Services Accounting System
theme: minima

plugins:
  - jekyll-relative-links
  - jekyll-seo-tag

collections:
  docs:
    output: true
    permalink: /:collection/:path/
```

### Create `Gemfile`

```ruby
source 'https://rubygems.org'
gem 'github-pages', group: :jekyll_plugins
```

## Option 3: Using VuePress or VitePress (Recommended)

For a more modern documentation experience:

### Install VitePress

```bash
npm install -D vitepress
```

### Create `docs/.vitepress/config.js`

```javascript
import { defineConfig } from 'vitepress'

export default defineConfig({
  title: 'PSA Documentation',
  description: 'Professional Services Accounting System',
  
  themeConfig: {
    nav: [
      { text: 'Guide', link: '/guide/' },
      { text: 'Deployment', link: '/deployment/' },
      { text: 'Runbooks', link: '/runbooks/' },
      { text: 'API', link: '/api/' }
    ],
    
    sidebar: {
      '/deployment/': [
        { text: 'Overview', link: '/deployment/' },
        { text: 'Laravel Forge', link: '/deployment/forge' },
        { text: 'Laravel Sail', link: '/deployment/sail' },
        { text: 'Docker', link: '/deployment/docker' }
      ],
      '/runbooks/': [
        { text: 'Overview', link: '/runbooks/' },
        { text: 'Backup & Restore', link: '/runbooks/backup-restore' }
      ]
    }
  }
})
```

### Update `package.json`

```json
{
  "scripts": {
    "docs:dev": "vitepress dev docs",
    "docs:build": "vitepress build docs",
    "docs:preview": "vitepress preview docs"
  }
}
```

### Update GitHub Pages Source

1. Go to **Settings** > **Pages**
2. Select:
   - **Source**: GitHub Actions

3. Create `.github/workflows/docs.yml`:

```yaml
name: Deploy Documentation

on:
  push:
    branches: [main, phase-6]
  workflow_dispatch:

jobs:
  deploy:
    runs-on: ubuntu-latest
    permissions:
      contents: write
    steps:
      - uses: actions/checkout@v4
      
      - name: Setup Node
        uses: actions/setup-node@v4
        with:
          node-version: '20'
          
      - name: Install dependencies
        run: npm ci
      
      - name: Build docs
        run: npm run docs:build
      
      - name: Deploy to GitHub Pages
        uses: peaceiris/actions-gh-pages@v3
        with:
          github_token: ${{ secrets.GITHUB_TOKEN }}
          publish_dir: docs/.vitepress/dist
```

## Local Development

### Preview GitHub Pages (Jekyll)

```bash
bundle install
bundle exec jekyll serve --livereload
```

Access at `http://localhost:4000`

### Preview VitePress

```bash
npm run docs:dev
```

Access at `http://localhost:5173`

## Custom Domain (Optional)

1. Go to **Settings** > **Pages**
2. Enter your custom domain under **Custom domain**
3. Add DNS records:
   - `A` record: `185.199.108.108` - `185.199.109.108` - `185.199.110.108` - `185.199.111.108`
   - `CNAME` record: `username.github.io` → your domain

## Best Practices

1. **Use consistent frontmatter** in all Markdown files:
   ```yaml
   ---
   title: Page Title
   description: Page description for SEO
   ---
   ```

2. **Add images** to an `assets/images/` folder

3. **Link between pages** using relative paths

4. **Update the README** to link to your documentation site

5. **Set up a contribution guide** for documentation updates

## Troubleshooting

### 404 Errors

- Ensure file names match links exactly (case-sensitive)
- Add `index.html` to directories
- Check GitHub Pages source settings

### Build Failures

- Check Jekyll version compatibility
- Review GitHub Actions logs
- Verify file permissions

## Additional Resources

- [GitHub Pages Documentation](https://docs.github.com/en/pages)
- [Jekyll Documentation](https://jekyllrb.com/docs/)
- [VitePress Documentation](https://vitepress.dev/)
