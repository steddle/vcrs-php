# steddle/vcrs-php

The reference validator of the [Verified Claimant Register Standard](https://vcrs.org). It grades every record of a facts export by the rules of the core's section 4, measures coverage at each grade, computes the snapshot root, each record's inclusion proof and the manifest, reports the findings of section 11.6, and drafts the conformance statement. It reads the annexes and the schemas from [steddle/vcrs](https://github.com/steddle/vcrs), the standard at the same version.

A validator implements one version of the standard and checks an export that names it. Install it at the version the export names, with the standard beside it:

```bash
composer require steddle/vcrs-php:{version} steddle/vcrs:{version}
```

Every version so far is a pre-release, and Composer installs the standard's pre-release only when it is named as well. The validator needs PHP 8.3 or later, with mbstring.

```bash
vendor/bin/vcrs check register.json --records=records.csv
```

`vendor/bin/vcrs` without a command lists the commands and the exit codes. The standard's `templates/` hold an example to run it on: `register.json` with `records.csv`, the same register as one file in `export.json`, and `expected.json`, which states every outcome `--expect` checks.

This repository is a read-only split of the one VCRS is changed in, and its tests run there, against the standard's folder. Comments go to [steddle/vcrs](https://github.com/steddle/vcrs/issues) as issues.

The validator is under the MIT licence, the standard under CC BY 4.0.
