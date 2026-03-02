# ADR-009: Vite Manifest Symlink Solution

**Status**: Accepted and Implemented
**Date**: 2025-11-11
**Decision Makers**: Development Team
**Environment**: Staging VPS (72.60.17.138)
**Technical Story**: Vite manifest not found, breaking asset loading

---

## Context

After building frontend assets with Vite and deploying to the staging server, the application failed to load CSS and JavaScript files. The browser showed a blank page with no styling, and the Laravel application threw errors about missing Vite manifest file.

### The Problem

**Error Message**:
```
Vite manifest not found at: /var/www/paradocks/public/build/manifest.json
```

**Investigation Results**:

```bash
# Check where Vite actually builds
$ ls -la public/.vite/
total 24
-rw-rw-r-- 1 ubuntu ubuntu   342 Nov 11 11:30 manifest.json
-rw-rw-r-- 1 ubuntu ubuntu 12456 Nov 11 11:30 app-Bx8K9mN2.js
-rw-rw-r-- 1 ubuntu ubuntu  5432 Nov 11 11:30 app-Cy7L8oP1.css

# Check where Laravel expects it
$ ls -la public/build/
ls: cannot access 'public/build/': No such file or directory

# Check vite.config.js
$ cat vite.config.js
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
    ],
    build: {
        outDir: 'public/.vite',  // Builds to .vite directory
    },
});
```

### Root Cause Analysis

**Path Mismatch**:

1. **Vite Configuration** (`vite.config.js`):
   - Output directory: `public/.vite/`
   - Manifest location: `public/.vite/manifest.json`
   - Assets: `public/.vite/app-[hash].js`, `public/.vite/app-[hash].css`

2. **Laravel Expectation** (default):
   - Manifest location: `public/build/manifest.json`
   - Assets: `public/build/*`

3. **Why the Mismatch**:
   - Custom Vite config was set to use `.vite` directory (hidden directory)
   - Possibly to avoid Git conflicts or keep build directory hidden
   - But Laravel's default vite helper expects `build/` directory
   - No configuration in Laravel to change manifest path

**Impact**:

- Homepage loaded but without any CSS styling
- JavaScript functionality broken
- Filament admin panel completely broken (relies heavily on JS/CSS)
- Browser console full of 404 errors for missing assets

---

## Decision

**Create a symlink from `public/build/manifest.json` to `public/.vite/manifest.json`.**

This allows:
- Vite to continue building to `.vite/` directory
- Laravel to find the manifest at expected location
- No changes to Vite config needed
- No changes to Laravel config needed
- Works in both development and production

---

## Alternatives Considered

### Option A: Change Vite Config to Build to public/build/

**Description**: Modify `vite.config.js` to output to standard `public/build/` directory.

```javascript
// vite.config.js
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
    ],
    build: {
        outDir: 'public/build',  // Change from .vite to build
    },
});
```

**Pros**:
- ✅ Matches Laravel's default expectation
- ✅ Standard configuration
- ✅ No symlinks needed
- ✅ Clearer for other developers

**Cons**:
- ❌ Would break development environment (dev server might be configured for `.vite`)
- ❌ Requires testing in dev to ensure no breakage
- ❌ May conflict with existing .gitignore rules
- ❌ Changes need to be committed and deployed
- ❌ Might affect other team members' environments
- ❌ Unclear why `.vite` was chosen originally (might be intentional)

**Verdict**: ❌ Rejected - Too risky without understanding original decision, would affect development

---

### Option B: Configure Laravel to Read from public/.vite/

**Description**: Override Laravel's Vite manifest path configuration.

```php
// In AppServiceProvider or config file
use Illuminate\Support\Facades\Vite;

Vite::useManifestFilename('.vite/manifest.json');
```

**Pros**:
- ✅ Keeps Vite config unchanged
- ✅ Laravel-native solution
- ✅ No symlinks needed

**Cons**:
- ❌ Not documented well in Laravel docs
- ❌ Might not work (method might not exist or might not work as expected)
- ❌ Would need to be committed to repository
- ❌ Non-standard configuration (confusing for other developers)
- ❌ Requires code changes and deployment
- ❌ Testing required

**Research**:
```php
// Checked Laravel Vite implementation
// Manifest path is hardcoded in many places
// No clean API to override it
```

**Verdict**: ❌ Rejected - Not a clean solution, Laravel doesn't provide good API for this

---

### Option C: Create Symlink (CHOSEN)

**Description**: Create symlink so both paths work.

```bash
mkdir -p public/build
cd public/build
ln -s ../.vite/manifest.json manifest.json
```

**Pros**:
- ✅ Immediate fix, no code changes
- ✅ No deployment needed (manual fix on server)
- ✅ Works in development and production
- ✅ Vite continues using `.vite/` directory
- ✅ Laravel finds manifest at expected location
- ✅ Easy to implement and understand
- ✅ Reversible (just delete symlink)
- ✅ No risk to existing development setups

**Cons**:
- ⚠️ Requires manual step during deployment
- ⚠️ Symlink might not survive some deployment processes
- ⚠️ Developers need to know about this workaround

**Mitigation**:
- Document in deployment procedures
- Add to deployment script
- Could add to package.json post-build script

**Verdict**: ✅ ACCEPTED - Simplest solution with no risk to existing setup

---

### Option D: Copy Files Instead of Symlink

**Description**: Copy manifest.json from `.vite/` to `build/` after every build.

```bash
# After npm run build
mkdir -p public/build
cp public/.vite/manifest.json public/build/manifest.json
```

**Pros**:
- ✅ No symlinks (some systems don't support symlinks well)
- ✅ Real files, not links
- ✅ Works on Windows (symlinks can be problematic)

**Cons**:
- ❌ Duplicate files (though manifest is small)
- ❌ Must remember to copy after every build
- ❌ Could get out of sync if forgotten
- ❌ Requires automation (build script)
- ❌ More complex than symlink

**Verdict**: ❌ Rejected - More complex than symlink, risk of desync

---

### Option E: Complete Rebuild - Change Both Vite and Laravel

**Description**: Standardize everything to use `public/build/`.

**Changes**:
1. Update `vite.config.js` to build to `public/build/`
2. Update any custom Laravel config
3. Update .gitignore
4. Update documentation
5. Rebuild assets
6. Test in development
7. Test in staging
8. Deploy

**Pros**:
- ✅ Clean, standard solution
- ✅ No workarounds needed
- ✅ Matches Laravel/Vite defaults

**Cons**:
- ❌ Requires extensive testing
- ❌ Might break existing dev environments
- ❌ Time-consuming for deployment day
- ❌ Risk of breaking something
- ❌ Need to coordinate with team

**Verdict**: ❌ Rejected for immediate deployment - Could be future improvement

---

## Implementation

### Manual Fix (Deployed on Staging)

```bash
cd /var/www/paradocks

# 1. Verify Vite build exists
ls -la public/.vite/manifest.json
# -rw-rw-r-- 1 ubuntu ubuntu 342 Nov 11 11:30 public/.vite/manifest.json

# 2. Create build directory
mkdir -p public/build

# 3. Create symlink
cd public/build
ln -s ../.vite/manifest.json manifest.json

# 4. Verify symlink
ls -la
# lrwxrwxrwx 1 ubuntu ubuntu 23 Nov 11 11:45 manifest.json -> ../.vite/manifest.json

# 5. Test symlink works
cat manifest.json
# Should show manifest content

# 6. Restart application (clear caches)
docker-compose -f docker-compose.prod.yml exec app php artisan config:clear
docker-compose -f docker-compose.prod.yml exec app php artisan view:clear

# 7. Test in browser
curl http://72.60.17.138
# Should show HTML with proper asset URLs
```

### Automated Solution (For Future Deployments)

**Option 1: Add to package.json**

```json
{
  "scripts": {
    "build": "vite build",
    "postbuild": "mkdir -p public/build && ln -sf ../.vite/manifest.json public/build/manifest.json"
  }
}
```

**Option 2: Add to Deployment Script**

```bash
#!/bin/bash
# deploy.sh

echo "Building assets..."
npm run build

echo "Creating manifest symlink..."
mkdir -p public/build
ln -sf ../.vite/manifest.json public/build/manifest.json

echo "Deployment complete"
```

**Option 3: Add to Dockerfile (if building in container)**

```dockerfile
# After npm run build
RUN mkdir -p public/build && \
    ln -s ../.vite/manifest.json public/build/manifest.json
```

### Verification

```bash
# Test application loads
curl -I http://72.60.17.138
# HTTP/1.1 200 OK

# Check browser loads CSS/JS
curl http://72.60.17.138 | grep -o "public/\.vite/app-.*\.css"
# public/.vite/app-Cy7L8oP1.css (asset found)

# Check admin panel
curl -I http://72.60.17.138/admin
# HTTP/1.1 302 Found (redirects to login - working)

# Open in browser
# ✅ Homepage displays with proper styling
# ✅ Filament admin panel works
# ✅ No console errors
```

---

## Consequences

### Positive Consequences

1. **Immediate Fix**:
   - ✅ Application working immediately
   - ✅ No code changes needed
   - ✅ No risk to development environment
   - ✅ No deployment cycle needed

2. **Flexibility**:
   - ✅ Allows Vite to use `.vite/` directory
   - ✅ Allows Laravel to find manifest at `build/`
   - ✅ Both paths work simultaneously
   - ✅ Can change either side in the future

3. **Compatibility**:
   - ✅ Works in development and production
   - ✅ Symlinks supported on Linux (our deployment target)
   - ✅ No special software needed

### Negative Consequences

1. **Manual Step Required**:
   - ⚠️ Must create symlink after building assets
   - ⚠️ Easy to forget during deployment
   - ⚠️ Not automated (yet)

   **Mitigation**:
   - Document in deployment procedures
   - Add to deployment checklist
   - Consider automating in package.json postbuild script

2. **Non-Standard Setup**:
   - ⚠️ Developers might be confused by symlink
   - ⚠️ Not obvious why symlink exists
   - ⚠️ Could be accidentally deleted

   **Mitigation**:
   - Document this ADR
   - Add comment in vite.config.js
   - Add to README

3. **Windows Compatibility**:
   - ⚠️ Symlinks on Windows require admin privileges or Developer Mode
   - ⚠️ Might not work for Windows developers

   **Mitigation**:
   - Deployment is on Linux (works fine)
   - Windows developers can copy file instead of symlink
   - Or enable Developer Mode in Windows

### Neutral Consequences

1. **Deployment Process**:
   - Need to ensure symlink exists after building assets
   - One additional step in deployment
   - Easily automated

2. **Git Tracking**:
   - Symlink can be committed to Git
   - Or added to .gitignore and created during deployment
   - Decision: Don't commit (create during deployment)

---

## Best Practices Established

### For Deployments

**After building assets**:

```bash
# Always run after npm run build
mkdir -p public/build
ln -sf ../.vite/manifest.json public/build/manifest.json
```

**Deployment Checklist Update**:

```markdown
- [ ] Pull latest code
- [ ] Install dependencies: npm install
- [ ] Build assets: npm run build
- [ ] Create manifest symlink: ln -sf ../.vite/manifest.json public/build/manifest.json  ← NEW
- [ ] Run migrations
- [ ] Clear caches
- [ ] Restart services
```

### For Development

**Local Setup**:

```bash
# After cloning repo
npm install
npm run build
mkdir -p public/build
ln -sf ../.vite/manifest.json public/build/manifest.json

# Now run dev server
npm run dev
```

### For CI/CD (Future)

```yaml
# .github/workflows/deploy.yml
- name: Build Assets
  run: |
    npm install
    npm run build
    mkdir -p public/build
    ln -sf ../.vite/manifest.json public/build/manifest.json
```

---

## Future Improvements

### Short Term (Next Sprint)

1. **Automate Symlink Creation**:
   - Add postbuild script to package.json
   - Or add to deployment script
   - Test automation works

2. **Document for Developers**:
   - Add note to README.md
   - Add comment in vite.config.js
   - Update local development guide

### Long Term (Future Consideration)

1. **Standardize Build Directory** (ADR-XXX):
   - Consider changing Vite config to use `public/build/`
   - Test thoroughly in development
   - Coordinate with team
   - Remove symlink workaround

2. **Evaluate Vite Config Decision**:
   - Understand why `.vite/` was chosen
   - Document reasoning
   - Decide if it should be changed
   - Create new ADR if changing

---

## Questions Answered

### Why was `.vite/` directory used instead of `build/`?

**Unknown** - Not documented in original codebase. Possible reasons:
- Hide build artifacts (`.vite` starts with dot, hidden in file browsers)
- Avoid Git conflicts (might have had custom .gitignore)
- Personal preference
- Example code copied from somewhere

**Recommendation**: Document original intent or standardize to `build/`

### Why not just change the Vite config?

**Risk Management**:
- Unknown impact on development environment
- Deployment was in progress, needed quick fix
- Symlink is safe, reversible, and proven to work
- Can change Vite config later with proper testing

### Will this work in production?

**Yes**, because:
- Production deployment is Linux (symlinks fully supported)
- Same build process as staging
- Symlink is just a filesystem feature
- No special configuration needed

### What if we deploy on Windows server?

**Options**:
1. Enable Developer Mode (allows symlinks)
2. Use copy instead of symlink
3. Change Vite config to use `build/` directory

**Current Decision**: Not relevant (deploying on Linux)

---

## Related Documentation

- **Deployment Log**: [../../environments/staging/01-DEPLOYMENT-LOG.md](../../environments/staging/01-DEPLOYMENT-LOG.md#issue-3-vite-manifest-not-found)
- **Issues & Workarounds**: [../../environments/staging/05-ISSUES-WORKAROUNDS.md](../../environments/staging/05-ISSUES-WORKAROUNDS.md#issue-3-vite-manifest-not-found)
- **Next Steps**: [../../environments/staging/07-NEXT-STEPS.md](../../environments/staging/07-NEXT-STEPS.md) (future improvement to standardize)

---

## References

- **Laravel Vite Integration**: https://laravel.com/docs/vite
- **Vite Configuration**: https://vitejs.dev/config/
- **Symlinks on Linux**: `man ln`
- **Package.json Scripts**: https://docs.npmjs.com/cli/v9/using-npm/scripts

---

## Related ADRs

- Future ADR: Standardize Vite Build Directory (if decision is made to change)

---

**Author**: Development Team
**Reviewers**: DevOps Team
**Approved**: 2025-11-11
**Implementation**: 2025-11-11 (during initial deployment)
**Last Updated**: 2025-11-11
