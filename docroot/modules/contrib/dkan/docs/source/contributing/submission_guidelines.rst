Submission Guidelines
=====================

Submitting an issue
-------------------

Before you submit an issue, please search the issue queue to see if an issue for your
problem already exists. Prior submissions might inform you of solutions readily available.

Before we can fix a bug we need to reproduce and confirm it. We ask that you provide
the exact steps needed to reproduce the error using the bug report issue template.
Please stick to the template.

Unfortunately if we are not able to reproduce the error, or we don't hear back from you,
we may close the issue.

Our efforts will be focused on the 2.x version of DKAN. If you are reporting a bug on 7.x-1x,
and it is not a security issue, we encourage you to reach out on the
`DKAN Discussions <https://github.com/GetDKAN/dkan/discussions>`_.

Setting up a local development sandbox
--------------------------------------

We recomend using DDEV with the DDEV-DKAN add-on as there are many helpful commands that will make it easier to get started.
Once you have composer, docker, and DDEV `installed <https://dkan.readthedocs.io/en/latest/installation/index.html>`_, head over to `Getting Started with DDEV-DKAN <https://getdkan.github.io/ddev-dkan/getting-started.html>`_.

Issues tagged Good First Issue
------------------------------

Issues tagged with the "Good First Issue" tag have been identified by experienced contributors as having some aspect that should be easy for a new contributor to do.

Submitting a Pull Request (PR)
------------------------------

**Development:** Fork the project, set up the development environment, make your changes in a
separate git branch and add descriptive messages to your commits.

**Test:** Before submitting a pull requests, test all of your changes. If your new code
changes existing functionality, update the existing tests. If you are adding functionality,
add test coverage for the new code.

**Pull Request:** After testing, commit your changes, push your branch to GitHub and send a
PR to the right project. If we suggest changes, make the required updates, rebase your branch
and push the changes to your GitHub repository, which will automatically update your PR.
At all times, please ensure the automated build passes so that a minimal amount of tests are passed for your code.

After your PR is merged, you can safely delete your branch and pull the changes from the main (upstream) repository.

Coding Standards
----------------

`Coder <http://drupal.org/project/coder>`_ is a tool to help write code for Drupal modules. It can detect and automatically fix coding standard errors. The project provides a coding standard for PHP_CodeSniffer based on the `Drupal coding standard <https://www.drupal.org/docs/develop/standards/php/php-coding-standards>`_.
