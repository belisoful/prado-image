# PRADO Image Extension

Read and write the metadata inside image files — EXIF, XMP, IPTC, ICC profiles and the rest —
from PHP, without re-encoding the picture. An extension for the
[PRADO PHP Framework](https://github.com/pradosoft/prado) 4.4+.

If you have ever needed to strip the GPS coordinates out of a photo before publishing it, add
a caption to a JPEG without putting it through another lossy generation, or find out which
lens took a shot, that is what this is for.

The central promise is that editing metadata leaves your pixels alone. These readers parse the
image **container** and rewrite only the parts you changed; the compressed image data comes
through byte-identical. When you *do* want to change the pixels, `getImage()` / `setImage()` /
`fromImage()` are there, and they carry the metadata across for you.

## Formats

Every format here is supported in both directions — reading and writing.

- **JPEG** — parsed segment by segment: JFIF/JFXX, EXIF, XMP, Kodak Meta, Picture Info,
  Photoshop IRB/IPTC, ICC, and the comment. Edit any of them and the entropy-coded scan is
  copied through untouched.
- **TIFF** — a complete TIFF 6.0 engine, both byte orders and all twelve data types. Strip and
  tile data is relocated with recomputed offsets, so metadata edits are lossless. The raster
  decodes too: uncompressed, PackBits, LZW and the CCITT fax codings, at 1–16 bits, striped or
  tiled, across bilevel, grayscale, palette, RGB, CMYK, YCbCr and L\*a\*b\*.
- **PNG** — chunk-level, with the normative chunk order maintained and CRCs recomputed. Reads
  and writes every carrier PNG defines, plus the `Raw profile type 8bim` text chunk that
  Photoshop, ImageMagick and ExifTool use to exchange IPTC (PNG has no IPTC chunk of its own).
  **APNG animation is first class** — frames round-trip byte-faithfully.
- **WebP / RIFF** — dimensions from `VP8`, `VP8L` or `VP8X`; ICC, EXIF and XMP read and
  written, with the `VP8X` header and its feature flags kept in step automatically.
- **GIF 87a and 89a** — the whole standard at block level, animation included. Frames are kept
  exactly as authored rather than coalesced, so sub-rectangles, disposal, interlacing and local
  palettes survive a round trip unchanged.

Two of those formats simply have nowhere to put IPTC — WebP and GIF — so `setIPTC()` throws
rather than accepting records it would quietly lose.

## Metadata

- **EXIF** — the full model over IFD0/EXIF/GPS/Interoperability/IFD1, with all 220 known tags
  of Exif 3.1 (CIPA DC-008-2026) and human-readable interpretation of their values. Rewriting
  **pins the maker note at its original byte offset**, because maker notes are full of pointers
  into themselves and moving one corrupts it.
- **Maker notes** — thirteen manufacturers decoded (Agfa, Canon, Casio, Epson, Fujifilm,
  Konica/Minolta, Kyocera/Contax, Nikon, Olympus, Panasonic, Pentax, Ricoh, Sony), quirks and
  all: forced byte orders, note-relative offsets, embedded TIFFs, packed camera settings.
- **XMP** — a DOM-backed model of the entire ISO 16684-1 grammar: arrays, nested structures,
  qualifiers and language alternatives. It knows the standard schemas, so writing `dc:title`
  gives you a language alternative and `dc:subject` a `Bag` without your having to say so.
  Extended XMP in JPEG is split and rejoined transparently.
- **IPTC (IIM 4.1)** — the full record set as a map, addressable by tag name or `record#dataset`.
- **Photoshop IRB** — every 8BIM resource, with typed decoders and the embedded IPTC bridge.
- **ICC profiles** — not just swapped whole but genuinely edited: every header field, typed tag
  reads and writes, and the profile-id digest. `TICCTransform` converts pixels between
  matrix/TRC spaces in pure PHP, which gives GD colour management it has no API for.
- And the smaller ones: **JFIF/JFXX** thumbnails, **JUMBF** boxes, **PrintIM**, **APP12 Picture
  Info**, the Photoshop **File Info** 22-field sync layer, and the **WAVE audio** files cameras
  record beside photographs.

## Two things worth knowing about

**Privacy scrubbing.** `clearPrivateData()` removes identifying information — location,
authorship, camera identity, serial numbers, timestamps, software, maker notes, thumbnails —
from every carrier a file holds, in a single call, by category. It leaves everything that
describes the picture itself, so what you get back is still a useful photo.

**Private spaces.** The maker notes an EXIF rewrite must not disturb are exposed as byte
ranges and bridged to the framework's reserved-space streams, so a consumer can edit around
them with the same protection the writer itself applies.

## Streams

Everything works with PSR-7 streams and PHP stream resources as well as strings and files —
`fromStream()` and `writeTo()` throughout, partial writes honoured.

```php
$jpeg = TJPEG::fromStream(new TBinaryStream(TStream::fromFile('in.jpg')));
$jpeg->getEXIF()?->setValueByName('Artist', 'A. Photographer');
$jpeg->writeTo(fopen('out.jpg', 'wb'));                 // resource out
$exif = TEXIF::fromStream(new TLimitStream($file, $len, $off));   // windowed parse
```

Very large TIFFs get a lazy scan: `TEXIF::scanFile()` seeks through the header, IFD chains and
thumbnail without ever reading the pixel data, so the metadata of a multi-gigabyte scan loads
in kilobytes.

```php
$exif = TEXIF::scanFile('500MB-scan.tif');   // seeks; never loads the pixels
$exif->getMake();
$exif->getThumbnail();
```

## Requirements

| Requirement | Scope | Purpose |
|---|---|---|
| PHP 8.1 or higher | required | The only hard requirement |
| PRADO Framework `^4.4` | dev | `TComponent`/`TMap`, the `TStream` IO layer (`TLimitStream`, `TResourceType`, the stream filters), and `Prado\IO\Compression\ICompressor` |
| `ext-gd` | suggested | Thumbnail/caption conversion as `\GdImage` (the preferred `TImageGraphics` library); generates the unit-test fixtures |
| `ext-imagick` | suggested | Thumbnail/caption conversion as `\Imagick` (the alternate `TImageGraphics` library) |
| `ext-iconv` | suggested | IPTC charset conversion via the framework's `TEscCharsetConverter` |

## Installation

```sh
composer require belisoful/prado-image
```

## What it provides

| Class | Role |
|---|---|
| `TImageFile` | The abstract reader base: `fromString()`/`fromStream()`/`fromFile()` factories, `getWidth()`/`getHeight()`/`getFormat()`, IPTC and ICC profile accessors, and `toBinary()`/`toStream()`/`save()` composition |
| `TJPEG` | The JFIF/EXIF JPEG reader/writer: keeps every segment in order, parses JFIF/JFXX, Photoshop IPTC, and the ICC profile into editable objects, preserves the scan verbatim on metadata edits and swaps it on `setImage()`; protected hooks let a subclass ingest additional markers (e.g. APP1 EXIF/XMP) |
| `TPNG` | The PNG chunk reader/writer: `IHDR` dimensions, order-maintaining chunk mutators, read-write `iCCP` ICC / `eXIf` EXIF / `iTXt` XMP / 8BIM IRB (and its IPTC), raster `getImage()`/`setImage()`, and animated PNG (`getApngFrames()`/`setApngFrames()`/`fromApngImages()`) |
| `TAPNGFrame` | One authored APNG frame: geometry (size, canvas offset), delay, disposal and blend operations, and the frame image data |
| `TWebP` | The WebP reader/writer: a RIFF container with dimensions from `VP8`/`VP8L`/`VP8X`, read-write `ICCP`/`EXIF`/`XMP ` chunks with their `VP8X` flags, and raster `getImage()`/`setImage()` |
| `TRIFF` | The generic RIFF container walker (WAV, AVI, WebP): form type plus chunk id/size/offset/payload, with `setChunk()`/`addChunk()`/`prependChunk()`/`insertChunk()`/`setChunkInOrder()`/`removeChunk()` |
| `TGIF` | The GIF87a/GIF89a reader/writer: logical screen descriptor, global color table, the ordered frame and extension stream, loop count, comments, read-write XMP (`XMP DataXMP`) and ICC (`ICCRGBG1012`), raster `getImage()`/`setImage()`, and byte-faithful composition |
| `TGIFFrame` | One authored GIF frame: sub-rectangle, interlace, local color table, LZW pixel indexes, and the graphic control fields (delay, disposal, user input, transparency) |
| `TGIFExtension` | One GIF extension block, with its sub-block framing and application identity preserved verbatim, and the raw-payload mode (magic trailer) the XMP packet needs |
| `TImageChunk` | One chunk of a chunked container (PNG or RIFF): type, size, offset, data |
| `TPhotoshop8BIM` | The Photoshop `8BIM` image-resource codec wrapping IPTC in a JPEG APP13 segment: `iptcDecode()`/`iptcEncode()` |
| `TIPTC` | The IPTC IIM 4.1 record set as a `TMap`: array access by tag name or `record#dataset` id, per-dataset validation and coercion, `parse()`/`toBinary()`, the `hasIPTC()`/`hasEXIF()`/`hasXMP()`/`hasICCProfile()` common-metadata accessors (with get/set), and GD conversion of the 1-bit `RasterizedCaption` dataset |
| `TIPTCTags` / `TIPTCRecords` | The IIM dataset identifiers (Envelope, Application, NewsPhoto, object-data records) and record-number enumerations |
| `TJFIF` | The JPEG APP0 JFIF data: version, pixel density and units, and an optional RGB thumbnail (≤ 255x255) |
| `TJFXX` | The JPEG APP0 JFXX thumbnail extension: JPEG, palette, or RGB encodings, with GD/Imagick conversion both ways |
| `TJFIFFormat` | The JFIF/JFXX embedding-mode enumeration (including the pick-the-most-compact `JFXXEfficiency`) |
| `TTIFF` | The TIFF image file, read-write: dimensions, full EXIF metadata, IPTC, ICC profile, XMP (tag 700) and the Photoshop IRB, lossless metadata rewriting, raster decode/encode (`getImage()`/`setImage()`/`fromImage()`) across none/LZW/PackBits/CCITT compressions, and the private-space stream views (`getReservedSpaces()`/`toReservedSpaceStream()`/`toFreeSpaceStream()`) |
| `TCCITTFaxCompressor` | The CCITT bilevel fax codec, encode and decode: Modified Huffman, Group 3 (1D and 2D), Group 4 |
| `TTIFFRaster` | The TIFF raster decoder behind `TTIFF::getImage()`: strips and tiles, chunky and planar, 1/2/4/8/16-bit samples, either fill order, and the bilevel/grayscale/palette/RGB/CMYK/YCbCr/L\*a\*b\* photometrics, normalized to 8-bit RGB |
| `TTIFFDocument` / `TTIFFIfd` / `TTIFFTag` / `TTIFFDataType` | The TIFF 6.0 engine: tolerant parsing (warnings, not failures), offset-recomputing composition with pinned value areas, and all twelve data types |
| `IPrivacyScrubbable` | The privacy contract: `clearPrivateData(int $types = TPrivacyCategory::All): int` on every metadata carrier and every image container |
| `TPrivacyCategory` | The identifying-information categories `clearPrivateData()` removes, one bit each (Location, Author, Description, CameraModel, SerialNumber, Timestamp, Software, MakerNote, Thumbnail, Interoperability) with the `Identity`/`Provenance` presets and `All` (-1); its docblock tabulates what each flag removes in each carrier |
| `TEXIF` | The EXIF model: named IFDs, tag access by name (`getValueByName('FNumber')`), interpreted text (`getTextByName`), the IFD1 thumbnail, the embedded IPTC/XMP/IRB/PIM/makernote bridges, the private-space stream views that protect the maker notes on write, and `clearPrivateData()` for privacy scrubbing by category |
| `TEXIFTags` | The EXIF-family tag knowledge base: TIFF/EXIF/GPS/Interoperability/Kodak groups with names, lookups, units, and special decoders |
| `TMakernote` (+ `TCanonMakernote`, `TKonicaMinoltaMakernote`) | The camera-makernote decoder, driven by the maker facts in `TMakernoteTags::Headers`, with a registerable per-maker class map |
| `TXMP` | The DOM-backed XMP packet: the full value grammar (arrays, nested and array-of structures, qualifiers, language alternatives), path expressions, enumeration, date/boolean helpers, the standard prefix registry, and xpacket serialization options |
| `TXMPSchemas` | The XMP schema registry: each standard property's declared form (LangAlt, Bag, Seq, Alt, Struct, Simple) across dc, xmp, xmpRights, xmpMM, xmpBJ, xmpTPg, xmpDM, photoshop, IPTC Core/Extension, PLUS, tiff, and exif — used to pick the right form on write and to `validate()` on read |
| `TPhotoshopIRB` / `TPhotoshopResource` | The Photoshop 8BIM resource set with per-resource typed decoders and APP13 chunking |
| `TFileInfo` | The Photoshop File Info emulation: the 22 synchronized fields across EXIF + XMP + IRB/IPTC |
| `TJUMBFBox` / `TJUMBFDescription` | The JUMBF box model (ISO/IEC 19566-5): superboxes, description boxes with the reserved content-type UUIDs, and the `xml()`/`json()`/`exifAnnotation()` builders; carried by `TJPEG::getJumbfBoxes()`/`setJumbfBoxes()` as APP11 |
| `TEXIFAudio` | The Exif WAVE audio file: the `exif` LIST attribute chunks, `fmt `/duration accessors, and lossless rewriting of the audio |
| `TPIM` | The Print Image Matching block codec (byte-order aware, Panasonic quirk handled) |
| `TPictureInfo` | The legacy APP12 picture-info text with vendor signatures and `Key=Value` field parsing |
| `TImageGraphics` | The graphics-library seam: routes RGB24 and CMYK export/import, decode, JPEG/PNG/WebP encode, ICC profile read/attach/transform, resample, black-and-white reduction, and palette quantization to the backend of the image's own library (or of the requested mode), with a settable default, a `supports()` capability query, and `getCapableLibrary()` |
| `TICCProfile` | The editable ICC profile: every header field readable and writable, the profile-id digest, the tag table with typed reads and writes (text/`mluc` localized text, `XYZ `, `curv`/`para` curves, `sf32` arrays, colorant matrix, tone curves), tag removal and aliasing, and offset-recomputing composition |
| `TICCTransform` | The pure-PHP color transform between two matrix/TRC profiles, giving GD a color-managed conversion it has no API for |
| `IImageGraphicsLibrary` | The contract one graphics library implements: the raster operations, `encode()` (JPEG/PNG/WebP), the ICC profile pair, and `supports()` for the capability differences between backends |
| `TImageGraphicsGD` / `TImageGraphicsImagick` | The GD and ImageMagick implementations, each operating only on its own image type |
| `TImageGraphicsMode` | The graphics-library enumeration (`GD`, `Imagick`) |
| `TLZWCompressor` / `TGIFLZWCompressor` | TIFF-flavor and GIF-flavor LZW codecs, implementing the framework's `Prado\IO\Compression\ICompressor` |
| `TPackBitsCompressor` | The TIFF/Macintosh PackBits run-length codec |
| `THorizontalPredictor` | The TIFF horizontal-predictor transform that improves LZW/deflate ratios |
| `TLZWFilter` / `TPackBitsFilter` / `THorizontalPredictorFilter` | The streaming `TStreamCodecFilter` counterparts of the codecs |

The `image_*`/`iptc_*`/`jfif_*`/`gif_*` error codes and the Prado3 short class names are registered by Composer from `composer.json` `extra.prado` (`config/errorMessages.txt`, `config/classMap.json`).

## Usage

### Reading dimensions and metadata

```php
use Prado\IO\Image\TJPEG;
use Prado\IO\Image\TPNG;
use Prado\IO\Image\TWebP;

$jpeg = TJPEG::fromFile('photo.jpg');           // or fromString() / fromStream()
[$w, $h] = [$jpeg->getWidth(), $jpeg->getHeight()];

$png  = TPNG::fromFile('image.png');
$icc  = $png->getICCProfile();                  // null when absent

$webp = TWebP::fromFile('image.webp');          // VP8 / VP8L / VP8X all supported
```

### Editing IPTC and saving without re-encoding

```php
use Prado\IO\Image\TJPEG;
use Prado\IO\Image\Meta\TIPTC;
use Prado\IO\Image\Meta\TIPTCTags;

$jpeg = TJPEG::fromFile('photo.jpg');

$iptc = $jpeg->getIPTC() ?? new TIPTC();
$iptc[TIPTCTags::CaptionAbstract] = 'Edited caption';
$iptc['Keywords'] = ['prado', 'image', 'metadata'];   // repeatable dataset
$jpeg->setIPTC($iptc);

$jpeg->save('photo-out.jpg');    // image data unchanged, metadata rewritten
```

### JFIF density and thumbnails

```php
$jfif = $jpeg->getJFIF();
[$jfif->getXDensity(), $jfif->getYDensity(), $jfif->getUnits()];
$jfif->setImage($gdThumbnail);   // embed an RGB thumbnail (<= 255x255)

// JFXX is the APP0 thumbnail extension, with JPEG, palette, or RGB encodings.
$jfxx = $jpeg->getJFXX() ?? new TJFXX();
$jfxx->setImage($gdThumbnail, TJFXX::JPEG_THUMB);   // or PALETTE_THUMB / COLOR_THUMB / EFFICIENCY_THUMB
$thumb = $jfxx->getImage();                          // back to a \GdImage or \Imagick
$jpeg->setJFXX($jfxx);
```

### EXIF: read, interpret, edit, rewrite

```php
use Prado\IO\Image\TJPEG;

$jpeg = TJPEG::fromFile('photo.jpg');
$exif = $jpeg->getEXIF();

$exif->getMake();                          // 'Canon'
$exif->getValueByName('FNumber');          // [[71, 10]]
$exif->getTextByName('FNumber');           // '7.1'
$exif->getTextByName('ExposureTime');      // '1/125 seconds'
$exif->getTextByName('GPSLatitude');       // '34° 3' 30"'
$thumb = $exif->getThumbnail();            // IFD1 JPEG bytes

$note = $exif->getMakernote();             // detected via the maker registry
$note?->getValues();                       // name => interpreted text
$note?->getThumbnail();                    // Casio/Olympus/Minolta embedded thumbnail

$exif->setValueByName('Artist', 'A. Photographer');
$jpeg->save('photo-out.jpg');              // rewritten; makernote pinned at its offset
```

GPS travels as decimal degrees, metres, and UTC instants — the helpers read and write
the reference-letter and degrees/minutes/seconds tag pairs (seeding the mandatory
`GPSVersionID` on first write):

```php
$exif->getLatitude();                      // -33.86882  (S negative, W negative)
$exif->setLatitude(60.392990);
$exif->setLongitude(5.324383);
$exif->setAltitude(-42.25);                // below sea level
$exif->setGpsTimestamp(new DateTimeImmutable('now'));   // stored as UTC
```

TIFF files read through the same model — `TTIFF::fromFile('scan.tif')->getEXIF()` — and the Kodak APP3 `Meta` block through `$jpeg->getMeta()`.

### Privacy: clearing identifying information

A photo leaving a user's control carries where, when, by whom, and with what it was
taken — and it says so in several places at once: EXIF, XMP, IPTC, the Photoshop IRB,
and format-specific fields such as the JPEG comment or PNG text chunks. Every carrier
and every container implements `IPrivacyScrubbable`, so `clearPrivateData()` removes
that by category — every category by default — while leaving the fields that describe
the picture (exposure, colour, dimensions), and the result is still a well-formed,
useful photo.

```php
use Prado\IO\Image\TPrivacyCategory;

// One call on the container reaches every metadata block it holds.
$jpeg = TJPEG::fromFile('photo.jpg');
$removed = $jpeg->clearPrivateData();                                // everything: the safe default
$jpeg->save('photo-shareable.jpg');

$png->clearPrivateData(TPrivacyCategory::Location);                  // GPS in EXIF and XMP, IPTC place names
$tiff->clearPrivateData(TPrivacyCategory::All & ~TPrivacyCategory::CameraModel);   // keep make/model
$webp->clearPrivateData(TPrivacyCategory::Identity | TPrivacyCategory::Location);  // people + place
$gif->clearPrivateData(TPrivacyCategory::Provenance);                // where, when, and with what software

// Or scrub a single carrier when that is what you hold.
$exif = $jpeg->getEXIF();
$exif->clearPrivateData(TPrivacyCategory::MakerNote | TPrivacyCategory::Thumbnail);
$jpeg->setEXIF($exif);
```

The categories are one bit each: `Location` (the GPS IFD, XMP GPS and place names, IPTC
city/state/country), `Author` (artist, copyright, credit, contact, `xmpRights`, IRB URLs),
`Description` (titles, captions, keywords, comments, JPEG/GIF comments, PNG text),
`CameraModel`, `SerialNumber` (body/lens serials, XMP document/instance ids, IPTC job and
document ids), `Timestamp`, `Software`, `MakerNote` (a proprietary blob that routinely
embeds serials and owner names), `Thumbnail` (a copy of the image that survives cropping
— a classic redaction leak: IFD1, XMP thumbnails, IRB and IPTC previews, JFIF/JFXX), and
`Interoperability`; `Identity` and `Provenance` are presets, and `All` is `-1`. The return
value is the number of items removed, and a second call removes nothing. IIM makes the
IPTC envelope's date and number mandatory, so the scrub replaces an existing envelope
date with a fixed synthetic sentinel (`TIPTC::ScrubbedDate`) and re-derives the number
from it, instead of letting the next write stamp today's date back in.

### Private spaces: editing around the maker notes

A maker note carries pointers relative to its own position, so it must stay put when its EXIF is rewritten — the writer pins it. `TEXIF` and `TTIFF` expose those pinned ranges and hand the composed bytes to the framework's reserved-space stream decorators, so a consumer can edit the block while the maker notes are protected exactly as the writer protects them.

```php
use Prado\IO\Stream\TReservedSpaceMode;

$exif = $jpeg->getEXIF();
$exif->getReservedSpaces();                  // [[offset, length], …] within toBinary()

// A stream whose reserved ranges are untouchable. Skip writes through the free bytes
// and jumps the maker notes; Clip stops at them; Fail throws on any overlap.
$stream = $exif->toReservedSpaceStream(TReservedSpaceMode::Skip);
$stream->seek(0);
$stream->write($patch);                      // the maker notes come out byte-identical

// Or see only the editable bytes as one contiguous stream — the maker notes are absent.
$free = $exif->toFreeSpaceStream();
```

Only a **parsed** maker note has an on-disk offset to pin, so a freshly built one reserves nothing. `TTIFF` offers the same three methods for a TIFF file.

### XMP

```php
use Prado\IO\Image\Meta\TXMP;

$xmp = $jpeg->getXMP() ?? TXMP::blank();
$xmp->getTitle();                                          // x-default
$xmp->getTitle('de');                                      // language alternative
$xmp->setLangAlt(TXMP::NS_DC, 'title', ['x-default' => 'Sunset', 'de' => 'Sonnenuntergang']);
$xmp->setKeywords(['sunset', 'norway']);
$xmp->getByPath('xmpMM:History[1]/stEvt:action');          // 'created'
$xmp->addArrayItem(TXMP::NS_MM, 'History', ['stEvt:action' => 'edited'], 'Seq');
$xmp->getProperties();                                     // every property, by prefix:name
$jpeg->setXMP($xmp);                                       // extended XMP when > 64 KB

$png->setXMP($xmp);                                        // PNG iTXt
$webp->setXMP($xmp);                                       // WebP XMP chunk (adds VP8X)
```

### File Info: one edit, three stores

```php
use Prado\IO\Image\Meta\TFileInfo;

$info = TFileInfo::fromJpeg($jpeg);        // merged view: XMP, then IPTC, then EXIF
$info['title'] = 'Sunset over Bergen';
$info['copyrightstatus'] = TFileInfo::Copyrighted;
$info->applyTo($jpeg);                     // EXIF + XMP + IRB/IPTC updated together
$jpeg->save('photo-out.jpg');
```

### GIF animation, frame by frame

Frames are the ones the file actually stores — sub-rectangles, disposal methods and all
— so editing metadata or one frame's pixels leaves every other byte untouched.

```php
use Prado\IO\Image\TGIF;
use Prado\IO\Image\GIF\TGIFFrame;

$gif = TGIF::fromFile('animation.gif');
$gif->getFrameCount();                       // 12
$gif->getLoopCount();                        // 0 = forever, null = play once
$gif->getComments();                         // ['made with prado-image']

$frame = $gif->getFrame(0);
[$frame->getLeft(), $frame->getTop()];       // the sub-rectangle, not the canvas
$frame->getDelayTime();                      // hundredths of a second
$frame->getDisposalMethod();                 // TGIFFrame::DisposalRestoreBackground
$frame->getTransparentIndex();               // palette index, or null
$frame->getPixels();                         // one index byte per pixel, de-interlaced

$frame->setDelayTime(8);                     // retime the animation
$gif->setLoopCount(0);                       // loop forever
$gif->save('animation-out.gif');             // every other byte identical
```

Frames convert to and from images through the graphics seam, quantizing on the way in:

```php
$image = $gif->getFrameImage(0);             // \GdImage or \Imagick

$frame = new TGIFFrame();
$frame->setDelayTime(10);
$frame->setImage($photo);                    // quantized to a local color table
$gif->addFrame($frame);

$still = TGIF::fromImage($photo);            // a single-frame GIF from any image
```

Because unknown blocks round-trip raw with their identity intact, a GIF's `XMP DataXMP`
and `ICCRGBG1012` application extensions survive an edit — which is not true of every
GIF library.

### PNG: every carrier, and the pixels

PNG reads and writes each carrier the format defines. There is no IPTC chunk, so the
records travel inside the Photoshop image-resource block of the `Raw profile type 8bim`
text chunk — the form ImageMagick, Photoshop, and ExifTool exchange.

```php
use Prado\IO\Image\TPNG;
use Prado\IO\Image\TImageChunk;
use Prado\IO\Image\PNG\TPNGChunkType;

$png = TPNG::fromFile('image.png');

$png->setICCProfile($iccBytes);              // deflated iCCP chunk
$png->setEXIF($exif);                        // eXIf chunk (bare TIFF)
$png->setXMP($xmp);                          // iTXt XMP packet
$png->setIPTC($iptc);                        // stored in the 8BIM text chunk

$png->getICCProfile();                       // and each reads straight back
$png->getPhotoshopIRB();                     // the resource block the IPTC rides in

// Raw chunk access keeps the spec's normative order and recomputes each CRC.
$png->setChunk(new TImageChunk(TPNGChunkType::Gamma, 4, 0, pack('N', 45455)));
$png->removeChunk(TPNGChunkType::Text);

$image = $png->getImage();                   // decode the raster
$png->setImage($resized);                    // re-encode, carrying the metadata chunks across
$png->save('image-out.png');
```

Animated PNG frames are authored, decoded, and edited like GIF frames:

```php
use Prado\IO\Image\PNG\TAPNGFrame;

$apng = TPNG::fromApngImages([$frame0, $frame1, $frame2], 0.15, 0);   // 0.15s each, loop forever
$apng->getIsAnimated();                      // true
$apng->getFrameCount();                      // 3
$apng->getPlayCount();                       // 0 = forever

$frames = $apng->getApngFrames();            // TAPNGFrame[] with fcTL geometry + delay
$frames[1]->setDelaySeconds(0.5);
$frames[1]->setDisposeOp(TAPNGFrame::DisposeBackground);
$apng->setApngFrames($frames);               // rebuilds acTL/fcTL/fdAT, renumbering sequences

$image = $apng->getApngFrameImage(0);        // decode one frame to a \GdImage or \Imagick
$apng->addApngFrame($extra, 0.2);            // append a frame from an image
$apng->save('animation.png');                // a still viewer shows the default image
```

### WebP: metadata in an extended-format container

WebP carries `ICCP`, `EXIF`, and `XMP ` chunks; setting any of them promotes a simple
file to the extended `VP8X` form and sets the matching feature flag. It defines no IPTC
carrier, so `setIPTC()` refuses rather than dropping the records.

```php
use Prado\IO\Image\TWebP;

$webp = TWebP::fromFile('image.webp');

$webp->setICCProfile($iccBytes);             // adds VP8X + the ICC flag if absent
$webp->setEXIF($exif);
$webp->setXMP($xmp);
$webp->getEXIF()?->getValueByName('Model');

try {
    $webp->setIPTC($iptc);                    // throws: WebP has no IPTC carrier
} catch (\Prado\Exceptions\TIOException $e) {
    // put the equivalent properties in XMP instead
}

$webp->setImage($resized, 80);               // re-encode the bitstream, metadata carried across
$webp->save('image-out.webp');
```

### GD or ImageMagick

Every method that takes or returns a raster accepts `\GdImage` and `\Imagick` alike, and builds whichever is asked for. GD is the default when both are loaded; `TImageGraphics::setDefaultMode()` changes that.

```php
use Prado\IO\Image\IImageGraphicsLibrary;
use Prado\IO\Image\TImageGraphics;
use Prado\IO\Image\TImageGraphicsMode;

$jfif->setImage($imagickThumbnail);                          // an \Imagick source works anywhere
$gd  = $jfif->getImage(TImageGraphicsMode::GD);              // ... and either library comes back out
$im  = $jfif->getImage(TImageGraphicsMode::Imagick);

TImageGraphics::setDefaultMode(TImageGraphicsMode::Imagick); // prefer ImageMagick for this app
$image = $jpeg->getIPTC()->getRasterizedCaptionImage();      // now an \Imagick by default
```

The backends are not interchangeable for every job, so ask before assuming: `supports()` answers per library, and never throws for one that is not installed.

```php
TImageGraphics::supports(IImageGraphicsLibrary::CapabilityCmyk);          // the default library
TImageGraphics::supports(IImageGraphicsLibrary::CapabilityWebP, TImageGraphicsMode::GD);
TImageGraphics::getLibrary(TImageGraphicsMode::Imagick)->supports(IImageGraphicsLibrary::CapabilityHighBitDepth);

$webp = TImageGraphics::encode($image, IImageGraphicsLibrary::FormatWebP, 80);   // false when unsupported
$png  = TImageGraphics::encode($image, IImageGraphicsLibrary::FormatPng);        // lossless: quality ignored

$cmyk = TImageGraphics::cmykPixels($image);                                      // 4 bytes/pixel
$image = TImageGraphics::fromCmykPixels($cmyk, $w, $h);

$profile = TImageGraphics::getICCProfile($image);        // ImageMagick only; null under GD
TImageGraphics::setICCProfile($image, $profile);         // over an existing profile: a transform
TImageGraphics::transformICCProfile($image, $sRgb, $adobeRgb);   // works in either backend
```

Carrying a profile on the image object needs ImageMagick — GD has nowhere to keep one and drops any profile on decode, so its `getICCProfile()` answers null and `setICCProfile()` false rather than pretending. Converting pixels between two spaces works in both: ImageMagick uses its color-management library (and handles lookup-table profiles), GD uses `TICCTransform`. When an ability matters more than the library, ask for the library that has it:

```php
$library = TImageGraphics::getCapableLibrary(IImageGraphicsLibrary::CapabilityICCEmbed);
$image = $library?->fromRgbPixels($rgb, $w, $h);         // built where the profile can ride along
```

### Image codecs

```php
use Prado\IO\Compression\TGIFLZWCompressor;
use Prado\IO\Compression\TPackBitsCompressor;

$lzw   = new TGIFLZWCompressor();
$bytes = $lzw->compress($indexStream, 8);      // GIF image data sub-blocks
$data  = $lzw->decompress($bytes, 8);

$packed = (new TPackBitsCompressor())->compress($scanline);
```

The `TLZWFilter`, `TPackBitsFilter`, and `THorizontalPredictorFilter` classes are the streaming counterparts, attachable to any framework stream as `TStreamCodecFilter`s.

### RIFF containers directly

```php
use Prado\IO\Image\TRIFF;

$riff = TRIFF::fromFile('audio.wav');
$riff->getFormType();            // 'WAVE'
foreach ($riff->getChunks() as $chunk) {
    [$chunk->getType(), $chunk->getSize(), $chunk->getOffset()];
}
```

## Development

```sh
composer install
composer unittest                                    # tests
vendor/bin/php-cs-fixer fix src                      # code style (tabs)
vendor/bin/php-cs-fixer fix tests                    # (the finder excludes tests/, so name it)
vendor/bin/phpstan analyse --memory-limit=1G         # static analysis (level 4)
```

Coverage is gated rather than merely reported, at two depths. **Lines** (99.92%) are checked
on every push; every source file must be complete except a handful whose remaining lines no
test can reach, each justified in [AGENTS.md](AGENTS.md):

```sh
XDEBUG_MODE=coverage vendor/bin/phpunit --testsuite unit --coverage-clover build/logs/clover.xml
php tests/test_tools/coverage-gate.php build/logs/clover.xml
```

**Branches** (99.67%) are checked nightly, because instrumenting every branch takes far
longer than the suite itself. It is the stronger measure: a covered line still hides a
decision that only ever goes one way. The twenty branches that remain are unreachable by
construction — mostly edges PHP emits itself, such as the implicit `UnhandledMatchError` of a
`match` whose subject is already range-checked:

```sh
XDEBUG_MODE=coverage php -d memory_limit=10G vendor/bin/phpunit --testsuite unit \
    --path-coverage --coverage-php build/logs/coverage.php
php -d memory_limit=8G tests/test_tools/branch-gate.php build/logs/coverage.php
```

Tests cover the format readers (dimensions and invalid-input rejection for JPEG/PNG/WebP, all three WebP variants, PNG ICC inflation); the **read-write-every-carrier matrix** across JPEG/PNG/WebP/TIFF/GIF (each container round-trips or explicitly refuses every metadata carrier its format defines, including the raster `getImage()`/`setImage()` paths); the GIF container (byte-faithful round trips of an animation exercising the whole standard, frame and extension editing, interlace, loop count, application-identity case, quantized import, and malformed-block rejection — cross-checked by decoding the composed files with GD and ImageMagick); the TIFF raster forms (tiles, planar, every bit depth, and the CMYK/YCbCr/Lab photometrics); the **private-spaces bridge** (reserved/free-space stream views that keep the maker notes byte-identical under Clip/Fail/Skip writes); the ICC profile coder and its pure-PHP color transform; the full XMP value grammar and schema registry; every makernote maker variant; the tag-interpretation decoders; the Photoshop resource decoders; the IPTC record set (parsing, tag-name and id access, validation/coercion, re-encode round trips); the Photoshop 8BIM codec (string and stream detection, IPTC decode/encode); the graphics seam (RGB and CMYK round trips, JPEG/PNG/WebP encode/decode, resampling, mono reduction, palette quantization, and the capability query in both libraries); and the compression codecs (LZW, GIF LZW, PackBits, CCITT fax, and predictor round trips plus their streaming filters). Test images and ICC profiles are generated in memory, so the repository carries no binary fixtures; the Imagick-path tests skip cleanly where `ext-imagick` is absent, and one CI job runs the whole suite without it to keep that promise honest. Test data is deterministic — `PseudoRandomBytes` stands in for `random_bytes()` — because random input makes both assertions and coverage vary from run to run.

> **Note:** the classes use the framework's IO stream layer (`TStream`, `TLimitStream`, `TResourceType`) and the `Prado\IO\Compression\ICompressor` contract. Both are on `pradosoft/prado` **master** but not yet in a tagged release, so `^4.4` resolves through the framework's `dev-master` branch alias for now, and picks up the 4.4 release as soon as it is tagged.

## License

BSD-3-Clause. See [LICENSE](LICENSE).
