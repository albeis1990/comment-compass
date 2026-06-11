# Comment Compass

Comment Compass is a small shared-hosting web app for generating IB primary end-of-semester and end-of-year report comments for Grades 1 to 5.

The OpenAI API key is used only by the PHP endpoint in `api/generate.php`. It is not sent to the browser.

## What Teachers Enter

- Student name, Grade, report period and pronouns
- The strongest evidence for praise
- Important subject evidence only
- One or two learner profile attributes with evidence
- One or two approaches to learning with evidence
- One or two positive next goals
- Exact sentence count from 5 to 30 sentences
- Optional school or parent support

## Comment Rules

The app prompt enforces these report-writing constraints:

- Formal report style, using passive voice where natural, with no first-person pronouns
- More positive achievement comments than improvement comments
- Concise sentences, even when a longer comment is requested
- Specific evidence from the teacher's notes
- No more than two improvement comments
- Positive next-step language
- A short final praise or encouragement
- Chinese-name-first format when a Chinese name is provided
- No UOI, P4C or ECA abbreviations
- Correct capitalization for English, Chinese, Philosophy for Children, Unit of Inquiry, Grade and Sports Day
- Lowercase maths or mathematics
- Parent-friendly wording with no unnecessary curriculum detail
- Teacher-selected exact length from 5 to 30 sentences
- Approved example-comment structure: learner-profile opening, subject evidence, wider-school contribution, separated goals, and final encouragement

## Local Preview

This repo uses a static browser UI and a PHP API endpoint. The UI can be previewed locally with:

```bash
npm install
npm run serve
```

The PHP endpoint needs a PHP-capable host with outbound HTTPS access. Local PHP is not required for the static preview.

## Shared Hosting Setup

Create an environment file outside the public web directory when possible:

```bash
OPENAI_API_KEY=sk-your-key-here
OPENAI_MODEL=gpt-5.5
APP_PASSCODE=choose-a-private-teacher-code
```

Recommended path for cPanel hosting:

```text
~/.comment-compass.env
```

If the app is deployed to `~/edlab.cc/comments/`, the PHP config loader will find `~/.comment-compass.env`.

## cPanel Git Deployment

The included `.cpanel.yml` deploys the app to:

```text
~/edlab.cc/comments/
```

This creates a directory version at:

```text
https://edlab.cc/comments/
```

For a subdomain instead, create the subdomain in cPanel and set its document root to:

```text
edlab.cc/comments
```

Then connect this GitHub repository through cPanel Git Version Control and run Deploy.

## Manual Upload

Upload these files and folders to the target directory:

- `index.html`
- `styles.css`
- `app.js`
- `.htaccess`
- `api/`
- `config/`

Do not upload `.env` to a public directory. Use `~/.comment-compass.env` instead.

## OpenAI Model

The default model is `gpt-5.5` for higher-quality report-comment generation. Change `OPENAI_MODEL` if a lower-cost or faster model is preferred.
