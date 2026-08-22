# PRADO Image Extension Agent Guidelines

## Build, Lint, and Test Commands

### Running Tests
- **All Unit Tests**: `vendor/bin/phpunit --testsuite unit` - runs all unit tests
- **Test Filter**: `vendor/bin/phpunit --testsuite unit --filter <test function, class, or directory>`

### Linting and Code Analysis
- **PHPStan Analysis**: `vendor/bin/phpstan analyse src/ --memory-limit=512M`
- **PHP CS Fixer (Dry-run)**: `vendor/bin/php-cs-fixer fix --dry-run src/` (check)
- **PHP CS Fixer (Fix)**: `vendor/bin/php-cs-fixer fix src/` (apply fixes)

### Build Commands
- **Install Dependencies**: `composer install` - installs all dependencies
- **Updating Dependencies**: `composer update` - updates all dependencies

## Code Style Guidelines
- "if" has a statement block after
- Use php-cs-fixer to correct code styles

### PHP Coding Standards
- Follow PSR-4 autoloading standard
- All PHP files must begin with `<?php` tag (short open tags not allowed)
- Use 1 tab for indentations (no spaces)
- All class names must be in PascalCase
- All method names must be in camelCase
- All variable names must be in camelCase
- Constants must be in SCREAMING_SNAKE_CASE
- All class properties must be declared with visibility modifiers (public, protected, private)

### Naming Conventions
- Class names: `TPascalCase` (e.g., `TComponent`)
- Class name prefix: `T*` (e.g. `TApplication`)
- Method names: `camelCase` (e.g., `getComponent`)
- Variables: `camelCase` (e.g., `$componentName`)
- Constants: `SCREAMING_SNAKE_CASE` (e.g., `MAX_RETRY_COUNT`)
- Namespace: `Prado\{Module}` (e.g., `Prado\Web\UI\TControl`)
- Template file extension: ".tpl"
- Web Page template file extension: ".page"

### Documentation Standards
- All public methods must have PHPDoc comments with:
  - `@param` for parameters
  - `@return` for return values  
  - `@throws` for exceptions
- Classes must have a clear and comprehensive docblock at the top with class description with:
  - Examples, where necessary
  - `@author` for attribution
  - `@method` for dynamic events with prefix 'dy-'; which are called (on "$this->dy-") but not defined.
- Inline comments should be in English and start with `//`
- Do NOT add `@since` tags: the extension is at its initial release (v1.0.0), so every symbol is "since 1.0.0" and the tag carries no information.
- All documentation should be written in present perfect tense

### Error Handling
- Use try/catch blocks for operations that can fail
- Throw appropriate PRADO exceptions (`TInvalidDataValueException`, `TInvalidOperationException`, etc.)
- Return false or null for methods that are designed to fail gracefully
- All methods should handle edge cases and validate input parameters
- Extension Exceptions use error codes (keys) defined in `config/errorMessages.txt`; the message text is purely for user information display only.

### Imports and Includes
- Use PSR-4 autoloading - no manual includes required
- All framework classes are accessed via namespace prefixes
- Third-party libraries are loaded via Composer
- Use proper `use` statements for namespaces at the top of PHP files


### Framework Specific Guidelines
- All components inherit from `TComponent` base class
- `TComponent` has features for dynamic event and extension by attached Behaviors (__call, __callStatic), dynamic properties (__get, __set, __isset, __unset), __clone, __sleep, __wakeup, and _getZappableSleepProps
- Behaviors can be attached to any `TComponent` to alter its behavior and functionality.
- Use the event-driven programming model with events; like `onLoad`, `onInit`, `onPreRender`
- Methods with prefix 'dy' are dynamic events to call attached and active Behaviors; like 'dyShouldContinue', 'dyClone', and 'dyValidate'
- Called Dynamic Events must be documented in the class phpdoc with "@method"
- Dynamic event are implemented by attached behaviors not in the calling class
- The first parameter of a dynamic event is always filtered and returned.
- Optional class methods can directly be called on non-behavior classes as "dynamic events"
- Methods with prefix 'fx' are global events that may or may not be automatically registered depending on getAutoGlobalListen(); like 'fxAttachClassBehavior'
- getAutoGlobalListen() is optimized by class hierarchy for utility and performance
- All events are raised in specified priority order
- Follow the TApplication Lifecycle: onInitComplete (at end of TApplication::initApplication) → onBeginRequest → onLoadState → onLoadStateComplete → onAuthentication → onAuthenticationComplete → onAuthorization → onAuthorizationComplete → onPreRunService → runService → onSaveState → onSaveStateComplete → onPreFlushOutput → flushOutput → onEndRequest or onError (both at end of TApplication::run)
- Follow the TPage Lifecycle (via TPageService::runPage): onPreInit → initRecursive → onInitComplete → loadPageState (POST/Callback) → processPostData (POST/Callback) → onPreLoad → loadRecursive → processPostData (POST/Callback) → raiseChangedEvents (POST/Callback) → raisePostBackEvent (POST-only) → processCallbackEvent (Callback-only) → onLoadComplete → preRenderRecursive  onPreRenderComplete → savePageState → onSaveStateComplete → renderControl (GET/POST) → renderCallbackResponse (Callback-only) → unloadRecursive
- XML and PHP is supported for application configuration 
- TPageService::onPreRunPage gives PRADO Modules event access to the TPage Lifecycle before it runs
- Framework core updates 'framework/classes.php' with new classes; this does NOT apply to this extension (see the PSR-4 / class-map note below).
- Web Pages are PHP classes with a ".page" TTemplate file with the same base name
- UI Portlets are PHP classes with a ".tpl" TTemplate file with the same base name
- Data components should support `TActiveRecord` pattern
- All UI controls should have proper template support and state management
- This is a new, pre-release extension with no published API to preserve, so backward compatibility is NOT a constraint; prefer the better design over a compatible one
- A full check consists of the 4 checks (in order): `php -l` compile, php-cs-fixer, phpstan, phpunit (all checks must pass successfully)
- A full check must be done for code to be ready for git commit.
- The current version of this extension is **v1.0.0** (initial release). It targets PRADO 4.4+. Because it is the initial release, source docblocks carry no `@since` tags.
- This extension namespaces its classes under `Prado\IO\Image\`, `Prado\IO\Image\TIFF\`, `Prado\IO\Image\ICC\`, `Prado\IO\Image\Meta\`, `Prado\IO\Image\Meta\Makernote\`, and `Prado\IO\Compression\` (PSR-4 `Prado\` → `src/`); extensions do NOT update the framework's `classes.php`. Prado3 short class names are supplied via `config/classMap.json`, registered by Composer from `composer.json` `extra.prado.class-map`.
- The tag knowledge bases (`TEXIFTags`, `TMakernoteTags`, `TMakernoteTables`, `TPhotoshopResourceNames`) are fact tables from the public specs; keep them complete and factual when extending.
- EXIF rewrites must keep the makernote pinned at its original offset (the `TTIFFTag::setPreserveOffset()` invariant). The pin predicate lives in **one** place — `TTIFFDocument::isPinned()` — which both `collectPins()` (the compose reservation) and `layoutIfd()` (the actual placement) call, so the reserved-space list can never drift from what the writer pins; do not re-inline that condition. `TEXIF`/`TTIFF` surface those ranges as `getReservedSpaces()` and bridge them to the framework's reserved-space stream decorators via `toReservedSpaceStream()`/`toFreeSpaceStream()` — the decorators own the write-through mechanics, so do not reimplement reserved-space stream logic here. TIFF files are read-write: keep the `TTIFFTag::setExternalData()` strip/tile capture-and-relocate mechanism (and its offsets/byte-counts pairing) intact on any writer change.
- Raster work goes through the `Prado\IO\Compression` codecs and `TImageGraphics`; the TIFF 6.0 coverage table is `agents/working/TIFF6-coverage.md`.
- Error codes (keys) and their messages live in `config/errorMessages.txt`, registered by Composer from `composer.json` `extra.prado.error-messages`; the framework's `messages.txt` is not used.
- The classes consume the framework's IO stream layer (`TStream`, `Prado\IO\Stream\TLimitStream`, `TResourceType`) and the `Prado\IO\Compression\ICompressor` contract. Both are in `pradosoft/prado` **master** but not in a tagged release, so the dev requirement is `^4.4 || dev-master` and Composer installs the framework from Packagist — there is no path repository and no symlink into a sibling checkout.
- The readers parse and rewrite the image **container** (segments/chunks); they never decode or re-encode the pixel data. Keep that property: an edit-and-save round trip must be byte-faithful outside the edited metadata.
- Known chunk/block type codes live in a per-format vocabulary — `TPNGChunkType` and `TRIFFChunkType` (4CC strings) and `TGIFBlockType` (byte labels), all `TEnumerable` — not as inline literals; reference the constants and add new codes there. These are **open vocabularies of the known codes, not closed types**: `TImageChunk::getType()` stays a raw string so unknown/private chunks round-trip byte-faithfully, so never type a chunk id as the enum or reject an id for being absent from it.
- Raster conversion is dual-backend via `TImageGraphics` (`Prado\IO\Image\TImageGraphics`), which routes to an `IImageGraphicsLibrary` implementation — `TImageGraphicsGD` or `TImageGraphicsImagick`: image-taking methods accept `\GdImage|\Imagick` and route by the image's own type, image-producing methods take an optional `TImageGraphicsMode` name (null = default; GD preferred, Imagick fallback). Do NOT call gd/imagick functions directly in the metadata classes; add the primitive to `IImageGraphicsLibrary`, implement it in BOTH backends, and delegate from the facade. When only one backend can do something, declare an `IImageGraphicsLibrary::Capability*` and gate on `TImageGraphics::supports()`; every capability needs an operation behind it (`CapabilityHighBitDepth` is the one documented exception). Prefer an honest software fallback in the weaker backend (as `TICCTransform` does for GD's ICC conversions) over approximating a result — a conversion that cannot be done exactly returns false/null so the caller can choose the capable library via `TImageGraphics::getCapableLibrary()`.

## Testing Guidelines
- The testing platform is "phpunit"
- All new code must include unit tests
- Unit test functions must comprehensively assert both typical and edge cases
- Maximal coverage of code execution paths of a class is required
- Test error conditions and exception handling
- Use mock objects where appropriate
- Functional tests should verify complete user workflows
- Tests should be isolated from each other (no shared state)
- Test images are generated in memory with `ext-gd` (imagejpeg/imagepng/imagewebp); do not commit binary image fixtures.
- Imagick-path tests must `markTestSkipped` when `ext-imagick` is not loaded; never hard-fail on a missing optional extension.
- When unit testing one or cluster of classes, only run the unit tests for that class or cluster/directory.
- Coverage drivers disagree about which line an `else` belongs to. Xdebug on PHP 8.1 has
  been observed marking an `else` body executed when only the `if` branch ran (measured in
  `TICCProfile::utf16BeToUtf8()`), while CI's pcov on 8.2+ reports it correctly — so a local
  100% can hide a branch no test drives. The gate in CI is the authority; when a line is
  reported uncovered there but covered locally, believe CI and write the test that actually
  exercises the branch. Do not chase it by weakening the gate.
- Coverage is gated at two depths and both are expected to hold. **Lines: 99.92%** —
  `tests/test_tools/coverage-gate.php`, run on every push. **Branches: 99.67%** —
  `tests/test_tools/branch-gate.php`, run nightly by `.github/workflows/branch-coverage.yml`,
  because a `--path-coverage` run takes far longer than the suite itself. Branch coverage is
  the stronger measure: it catches a decision that only ever goes one way, which a covered
  line hides. Every one of the 20 remaining untaken branches is unreachable by construction,
  and most are not code anyone wrote — PHP emits an implicit `UnhandledMatchError` edge for a
  `match` behind a range guard, an implicit `return null` after a `while (true)` that only
  exits by return or throw, an implicit `default` for a `switch` over a validated private
  field, and an implicit rethrow for a multi-catch whose `try` can only raise the listed
  types. The rest are guards made redundant by an identical earlier check. Do not chase them.
- Line coverage of `src` is **99.92%** and is expected to stay there: a change that adds
  an uncovered line is a change that needs a test.  Exactly five lines are knowingly
  unreachable from a test, and each is unreachable for a stated reason — do not "cover"
  them with contrived tests, and do not silence them with `@codeCoverageIgnore`:
  - `TCCITTFaxCompressor::writeRun()` — the `$makeup < 64` break.  Every multiple of 64
    from 64 to 2560 has a code in `ExtendedCodes` or in both colour tables, so the
    make-up search always succeeds on its first iteration.
  - `TJUMBFBox::toBinary()` — the 64-bit extended length.  Emitting it needs a single
    in-memory payload larger than 4 GiB.
  - `TImageGraphicsGD::monoPixels()` — the allocation guard.  `imagecreatetruecolor()`
    is called with the *source's* dimensions, so it can only fail for a source of
    ~537 M pixels that must already exist to be passed in (measured: 33 s, 3.7 GB).
    A low `memory_limit` does not help — GD allocates outside PHP's memory manager.
  - `TImageGraphicsImagick::paletteQuantize()` — the over-budget palette lookup.
    ImageMagick caps `quantizeImage(256, …)` at the requested colour count and the
    pixel export can only merge colours, never split them.  The branch's arithmetic is
    asserted directly by `TSmallApiTest::testGraphicsClosestPaletteIndex`.
  - Static analysis, not tests, is the tool for the other kind of gap: a branch that
    runs but whose condition never flips.  PHPStan level 4 found one such dead guard
    (`TKonicaMinoltaMakernote`) that 100% line coverage would never have revealed.
- NEVER add/change phpunit command options when unit testing; only run project unit tests as specified

## Development Environment
- PHP 8.1 or higher required
- PHP extensions: ctype, dom, intl, json, pcre, spl (required); gd (required for the unit tests' generated fixtures; one of gd/imagick is required for raster conversion); imagick (optional alternate graphics library); iconv (IPTC charset conversion)
- Composer for dependency management
- Required developer dependencies for code checking: phpunit/phpunit, phpstan/phpstan, friendsofphp/php-cs-fixer
- Presume that project dependencies are installed

## Cursor/Copilot Instructions
No specific Cursor or Copilot rules currently defined for this project.

# PRADO Framework Agent Safeguards -- ANTI-PATTERNS
Between the next brackets, it is required without exception:
{
- NEVER (without exception) execute the following "git" commands without asking the developer for approval first: clone, checkout, mv, restore, rm, branch, add, commit, merge, rebase, reset, pull, push, fetch
- NEVER (without exception) execute "rm" commands on any paths without asking the developer for approval first
- NEVER remove composer --dev dependencies because those are a required for development on the Project
- NEVER perform an action that erases or overwrites files for the task of unit testing and fixing; file changes are important and must be kept, because the changes themselves are being unit tested.
- NEVER delete any folders or files until the associated task is absolutely and totally complete.
}
