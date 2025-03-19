# Opinionated configurable GitHub Actions Workflows

These are my personal opinionated configurable GitHub Actions Workflows, aimed at my personal needs to be the single 
central point of all my GitHub Actions Workflows. Over the years most of my workflows started from one or two and 
diverged in all directions, this repository is to reel that all back in one easy to maintain them for all my 
repositories.

## Opinionated choices

### Runs On

Each workflow, well most of them, take two runs-on arguments `runsOnChaos` and `runsOnOrder`. As the names suggest 
`runsOnChaos` runs as many jobs at the same time as possible where `runsOnOrder` runs them one by one. For most 
jobs `runsOnChaos` is fine, but for things like `TerraForm` apply, and `Helm` install where state somewhere is 
changed and only one can run at the same time `runsOnOrder` is the way to go. Mainly came to this while working on 
getting the workflows managing my home cluster to work as intended:

![Home Cluster](./images/home-cluster.jpg)

When not set they all default to `ubuntu-latest` which means it will run on GitHub provided Runners.

### Makefile

All the quality control checks for the CI entry point are handled and controlled through Makefiles this gives each
project the control to check and assert everything relevant to it. When creating the task matrix the repositories the
Makefile is expected to have a `task-list-ci` command which will output a JSON array of tasks.

### Sparse Checkout

Sparse checkout is used as much as possible to keep job run times as long as possible. Several of my projects have
1GB+ binary test data checked in, this takes some time to checkout, maybe even more so on Raspberry Pis. By
aggressively using the `sparse-checkout` checkout option [`actions/checkout`](https://github.com/actions/checkout/)
provides checkout times plummeted. For the biggest repo this went down to 4 seconds from 66 seconds without sparse
checkout. This resulted in a few inputs being such as `ociSparseCheckout`, `helmSparseCheckout`, and
`terraformSparseCheckout`. Each of those 3 is used with `sparse-checkout-cone-mode: false` meaning that you can use
patterns like the following in there:
```yaml
sparseCheckout: |
  !/*
  /.terraform/*
  /**/*.md
  /composer.json
  /composer.lock
```

Also since those are free form fields you're expected to put in `workingDirectory` and `terraformDirectory` your self.
For example in the case of `TerraForm` the full checkout step is:

```yaml
 - uses: actions/checkout@v4
   with:
     sparse-checkout-cone-mode: false
     sparse-checkout: |
       !${{ inputs.workingDirectory }}/*
       /${{ inputs.workingDirectory }}${{ inputs.terraformDirectory }}*
       ${{ inputs.terraformSparseCheckout }}
```

### Versioning

Everything going through the package endpoints assumes [`Semantic Versioning`](https://semver.org/), and has
automation to automatically decide what the appropriate version bump is. Projects use monotonically increasing release
version, `r1`, `r2`, `r13`, `r66` etc etc.

### OCI images

All images are pushing to GitHub's container registry at `ghcr.io` with the full repository name as image name taken
from `${{ github.repository }}`. On each build image image tag will be `sha-COMMIT_SHA` where `COMMIT_SHA` is
taken from `${{ github.sha }}`.

Release images are tagged with the passed milestone title as the image tag.

## Entry points

There are to types of entry points supported. `Package` and `project`, the former is meant for 
PHP packages/GitHub Actions/Anything-that-is-not-a-project while the latter is for websites/services.

### Release Management

Each type has two entry points. The first being `Release Management`, which takes care of everything related to 
releasing new versions and all the information and bits required for that. This includes:

* Required labels
* Package Manager/Helm/TerraForm Diff
* OCI image retaging
* Helm deploy to Kubernetes
* Setting a milestone on PR's
* Creating a GitHub release
* Generating a changelog
* Push static content to a CDN

It should be triggered for the following events:

* Closing of a milestone
* PR's for the follow types: `opened`, `labeled`, `unlabeled`, `synchronize`, `reopened`, `milestoned`, `demilestoned`, and `ready_for_review`.

#### CI

The other entry point is `CI` it does everything related to validating the current push of the code meets expectations
through a series of [`Makefile`](#Makefile) commands. When present CI will also build the OCI (Docker) image if
present. Any control over the different platforms the OCI image is released for is done here, the release management
doing any retagging will detect which platforms are in the image it retags and uses those without the need to
configure them.

## What's included

And how to configure it.

### CI

The CI entry point only takes two configuration inputs `env` and `services`, both take a JSON string as argument.
For example adding the Redis DSN to the environment is written as:

```yaml
with:
  env: "{\"REDIS_DSN\":\"redis://redis:6379/6\"}"
```

While the service providing redis is written as:

```yaml
with:
  services: "{\"redis\":{\"image\":\"redis\",\"options\":\"--health-cmd \\\"redis-cli ping\\\" --health-interval 1s --health-timeout 5s --health-retries 50\"}}"
```

### Docker/OCI

The OCI image building and retagging responsibilities using Docker are split between the CI and Release Management
entry points. CI builds the images and decides what goes into them and for what platform, while Release Management
purely retags the images build by CI.

CI will only build images if you provide the `Dockerfile` you're using through the `dockerfile` input. Provide
`dockerBuildTarget` when using a multi-stage Docker file to build the desired target. Any additional arguments can be
passed to the Docker build command using `dockerBuildExtraArguments`.

By default the OCI build workflow will detect all the platforms the first upstream image found in `FROM` is build for
and build the image for the same platforms. If you want to override that pass an `CSV` of different platforms to the
`ociPlatforms` in put in this format: `linux/amd64,linux/arm`.

To speed up checking out during building `ociSparseCheckout` can be used to exclude any files we don't care about and
will slowdown our checkout. For example in one of my projects the `tests/data/` directory contains 1GB+ binary data
for testing, I have zero need to check that out during image build. So my sparse checkout allows everything but any
file from `tests/data/`:

```yaml
ociSparseCheckout: |
  /*
  !/tests/data/*
```

To kick off retagging simple set `ociRetag` to `true` on the Release Management entry point.

### Helm

When the `helmDirectory` is passed to the release management entry point isn't an empty string both the
Helm Diff and Helm Deploy workflows are executed against that directory.

As with any Helm chart being deployed we need to give it a name, the `helmReleaseName` is used to specify that.

By default a sparse checkout is performed and only the passed `helmDirectory` is checked out, if you need than
that you can use `helmSparseCheckout` with additional patterns to check out.

To set additional values or to overwrite existing values from `values.yaml` `helmAdditionalArguments` can be used to
pass any additional args to the diff and upgrade commands. For example  to get dynamically generated values from
another job:

```yaml
name: Release Management
on:
  pull_request:
    types:
      - opened
      - labeled
      - unlabeled
      - synchronize
      - reopened
  milestone:
    types:
      - closed
permissions:
  contents: write
  issues: write
  pull-requests: write
  packages: write
jobs:
  helm-json:
    name: Generate JSON for Helm
    runs-on: chaos
    outputs:
      helm-json: ${{ steps.helm-json.outputs.helm-json }}
    steps:
      - run: printf "helm-json=%s" $(echo "{\"LOGLEVEL\":\"DEBUG\"}") >> $GITHUB_OUTPUT
  release-management:
    name: Release Management
    needs:
      - helm-json
    secrets: inherit
    uses: WyriHaximus/github-workflows/.github/workflows/project-release-management.yaml@main
    with:
      helmAdditionalArguments: --set-json='application=${{ needs.helm-json.outputs.helm-json }}'
```

Because these workflows provide diff comments on PR's for Helm and others we need to specify `helmReleaseValueName` so
we can assign the previous tag on diff and closed milestone on deploy to that value. We can't use `helmAdditionalArguments` for this as we
autodetect the previous tag. Similarly `helmUpdateAppVersion` is used to update the `appVersion` in `Chart.yaml`,
when set to `true`, with the same value in those two scenarios.

### Linting

A few, maybe somewhat random, linters are included in the work flows. One of them checks if links in markdown
resources are responding with a ~200 status code and errors when they are no longer working.

### TerraForm

When the `terraformDirectory` is passed to the release management entry point isn't an empty string both the
TerraForm Diff and TerraForm Apply workflows are executed against that directory. The repository is expecting to have
the following secrets for TerraForm state storage on S3:

* `TERRAFORM_STATE_KEY`
* `TERRAFORM_STATE_SECRET`
* `TERRAFORM_STATE_BUCKET`
* `TERRAFORM_STATE_REGION`

By default a sparse checkout is performed and only the passed `terraformDirectory` is checked out, if you need than
that you can use `terraformSparseCheckout` with additional patterns to check out. Further more `terraformParallelism`
and `terraformLogLevel` can be used to tune TerraForm's behavior.

In order to inject variables into TerraForm `terraformVars` is used to create `terraform.tfvars` in
the `terraformDirectory`. Variables can be hard coded but secrets are available as environment variables:

```yaml
terraformVars: |
  kubernetes_config_path   = "~/.kube/config"
  kubernetes_context       = "$HOME_KUBE_CONTEXT"
  kubernetes_namespace     = "$HOME_KUBE_NAMESPACE"
```

## Usage

### Packages

#### CI

```yaml
name: Continuous Integration
on:
  push:
    branches:
      - 'main'
      - 'master'
      - 'refs/heads/v[0-9]+.[0-9]+.[0-9]+'
      - 'refs/heads/[0-9]+.[0-9]+.[0-9]+'
  pull_request:
## This workflow needs the `pull-request` permissions to work for the package diffing
## Refs: https://docs.github.com/en/actions/reference/workflow-syntax-for-github-actions#permissions
permissions:
  pull-requests: write
  contents: read
jobs:
  ci:
    name: Continuous Integration
    uses: WyriHaximus/github-workflows/.github/workflows/package.yaml@main
```

##### Inputs

| Input | Type | Description | Default |
|-------|------|-------------|---------|
| dependencyUpdaters | string | CSV list of bot AppId&#039;s that create PR&#039;s to updated dependencies like RenovateBot and DependaBot | 49699333 |
| env | string | Any additional environment variables | {} |
| jsonPattern | string | The pattern to match which JSON files to check | \.json$ |
| services | string | Any additional services to use | {} |
| workingDirectory | string | The directory to run this workflow in |  |

#### Release Management

```yaml
name: Release Management
on:
  pull_request:
    types:
      - opened
      - labeled
      - unlabeled
      - synchronize
      - reopened
      - milestoned
      - demilestoned
      - ready_for_review
  milestone:
    types:
      - closed
permissions:
  contents: write
  issues: write
  pull-requests: write
jobs:
  release-management:
    name: Create Release
    uses: WyriHaximus/github-workflows/.github/workflows/package-release-management.yaml@main
    with:
      milestone: ${{ github.event.milestone.title }}
      description: ${{ github.event.milestone.title }}
```

##### Inputs

| Input | Type | Description | Default |
|-------|------|-------------|---------|
| branch | string | The branch to tag the release on |  |
| description | string | Additional information to add above the changelog in the release |  |
| disableComposerLockDiff | boolean | Disable the diffing of composer lock files |  |
| disableSetMilestone | boolean | Disable the setting of milestones |  |
| initialTag | string | The tag to fallback to when no previous tag could be found. | 1.0.0 |
| labels | string | The labels to for the sections of the changelog | Bug 🐞,Dependencies 📦,Feature 🏗,Enhancement ✨,Deprecations 👋 |
| milestone | string | The milestone to tag |  |
| preReleaseScript | string | Script that runs just before the release is created |  |
| runsOnChaos | string | Define on which runner to run workflows where order doesn&#039;t matter should run | ubuntu-latest |
| runsOnOrder | string | Define on which runner to run workflows where order matters should run | ubuntu-latest |

### Projects

#### CI

```yaml
name: Continuous Integration
on:
  push:
    branches:
      - 'main'
      - 'master'
      - 'refs/heads/r[0-9]+'
  pull_request:
## This workflow needs the `pull-request` permissions to work for the package diffing
## Refs: https://docs.github.com/en/actions/reference/workflow-syntax-for-github-actions#permissions
permissions:
  pull-requests: write
  contents: read
  packages: write
jobs:
  ci:
    name: Continuous Integration
    uses: WyriHaximus/github-workflows/.github/workflows/project.yaml@main
    secrets: inherit
    with:
      runsOnChaos: chaos
      runsOnOrder: queue
```

##### Inputs

| Input | Type | Description | Default |
|-------|------|-------------|---------|
| dependencyUpdaters | string | CSV list of bot AppId&#039;s that create PR&#039;s to updated dependencies like RenovateBot and DependaBot | 49699333 |
| disableComposerLockDiff | boolean | Disable the diffing of composer lock files |  |
| disableMarkdownLinkCheck | boolean | Disable the checking of links in markdown files |  |
| disableRequiredLabels | boolean | Disable failing PR&#039;s when certain labels are missing |  |
| dockerBuildExtraArguments | string | Extra arguments to pass to the docker build command |  |
| dockerBuildTarget | string | Value for the --target flag |  |
| dockerfile | string | The Dockerfile to build |  |
| env | string | Any additional environment variables | {} |
| jsonPattern | string | The pattern to match which JSON files to check | \.json$ |
| ociPlatforms | string | The platforms to build the OCI image for, empty means autodetect |  |
| ociPushSecretSecret | string | The secret name that holds the token to push OCI images to GHCR.io | GITHUB_TOKEN |
| ociSparseCheckout | string | Sparse checkout patterns in cone mode |  |
| runsOnChaos | string | Define on which runner to run workflows where order doesn&#039;t matter should run | ubuntu-latest |
| runsOnOrder | string | Define on which runner to run workflows where order matters should run | ubuntu-latest |
| services | string | Any additional services to use | {} |
| workingDirectory | string | The directory to run this workflow in |  |

#### Release Management

```yaml
name: Release Management
on:
  pull_request:
    types:
      - opened
      - labeled
      - unlabeled
      - synchronize
      - reopened
  milestone:
    types:
      - closed
permissions:
  contents: write
  issues: write
  pull-requests: write
  packages: write
jobs:
  release-management:
    name: Release Management
    secrets: inherit
    uses: WyriHaximus/github-workflows/.github/workflows/project-release-management.yaml@main
    with:
      milestone: ${{ github.event.milestone.title }}
      description: ${{ github.event.milestone.description }}
      runsOnChaos: chaos
      runsOnOrder: queue
```

##### Inputs

| Input | Type | Description | Default |
|-------|------|-------------|---------|
| applicationType | string | The type of project this is, release and deployment wise |  |
| branch | string | The branch to tag the release on |  |
| cdnAwsAccessKeyIDSecret | string | The secret name that holds the AWS access key ID | CDN_HOSTED_S3_KEY |
| cdnAwsCloudFrontDistributionIDSecret | string | The secret name that holds the AWS cloudfront distribution id | CDN_HOSTED_DISTRIBUTION_ID |
| cdnAwsRegionSecret | string | The secret name that holds the AWS region | CDN_HOSTED_S3_REGION |
| cdnAwsS3BucketSecret | string | The secret name that holds the AWS S3 bucket name | CDN_HOSTED_S3_BUCKET |
| cdnAwsSecretAccessKeySecret | string | The secret name that holds the AWS access key secret | CDN_HOSTED_S3_SECRET |
| description | string | Additional information to add above the changelog in the release |  |
| disableSetMilestone | boolean | Disable the setting of milestones |  |
| helmAdditionalArguments | string | The directory to run this workflow in |  |
| helmDirectory | string | The directory to run this workflow in |  |
| helmReleaseName | string | The name of the helm release |  |
| helmReleaseValueName | string | The name of the value to use for releases |  |
| helmSparseCheckout | string | Additional files/patterns for the sparse checkout |  |
| helmUpdateAppVersion | boolean | Update the helm charts appVersion with the passed version |  |
| initialTag | string | The tag to fallback to when no previous tag could be found. | 1.0.0 |
| kubeConfigSecret | string | The secret name that holds the kubeconfig to connect with Kubernetes |  |
| labels | string | The labels to for the sections of the changelog | Bug 🐞,Dependencies 📦,Feature 🏗,Enhancement ✨ |
| milestone | string | The milestone to tag |  |
| ociPushSecretSecret | string | The secret name that holds the token to push OCI images to GHCR.io | GITHUB_TOKEN |
| runsOnChaos | string | Define on which runner to run workflows where order doesn&#039;t matter should run | ubuntu-latest |
| runsOnOrder | string | Define on which runner to run workflows where order matters should run | ubuntu-latest |
| terraformDirectory | string | The directory to run this workflow in |  |
| terraformLogLevel | string | Value for the TF_LOG environment value |  |
| terraformParallelism | number | Value for the -parallelism plan/apply flag | 13 |
| terraformSparseCheckout | string | Additional files/patterns for the sparse checkout |  |
| terraformVars | string | The directory to run this workflow in |  |
| type | string | The type of project this is, release and deployment wise | kubernetes |
| vitePressDirectory | string | The directory that container VitePress |  |
| workingDirectory | string | The directory to run this workflow in |  |

## TODO

- [ ] Tag `v1`(`.0`(`.0`)) - Needs to be done at some point as I want to version all of this in the same way as GitHub Actions are version with mutable major and minor tags and immutable patch tags.
- [X] OCI Build
- [X] OCI Retagging
- [X] Make all runs-on for projects configurable
- [X] Make all runs-on for packages configurable
- [ ] Get all CI QA checks to run on runsOn inputs instead of GitHub hosted Runners
- [X] Helm Diff
- [X] Helm Upgrading
- [ ] Helm Automatically detect all dependencies and load those in so we can remove hardcoding them in the workflows
- [X] TerraForm Diff (Plan)
- [X] TerraForm Apply
- [X] Terraform vars from secrets
- [X] Check links in Markdown files for non 200 status codes
- [ ] Make CI's test directly on OS runs-on array configurable
- [ ] Cronjob/Scheduled workflows for things like Docker image clean up
- [X] Sparse checkout all the things
- [ ] Fix typo in release management entry point filenames, and have all users point at the currect one
- [ ] Add documentation once Utils entry point is more stable
- [ ] Add GHCR.io image clean up to project utils entry point

## License

Copyright (c) 2025 Cees-Jan Kiewiet

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
