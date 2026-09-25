#!/usr/bin/env node
/**
 * OpenCode / OpenRouter Multi-Agent Advisory Runner for SANAD Lockdown Plugin
 * Part of Antigravity AI Engineering Architecture (Pillar 5: Graph Advisory)
 * Powered by Agency Agents (msitarzewski/agency-agents)
 */

const fs = require('fs');
const path = require('path');
const https = require('https');

const MODEL_ALIASES = {
  auto: 'openrouter/free',
  free: 'openrouter/free',
  bunny: 'stealth/space-bunny-alpha',
  reasoning: 'stealth/space-bunny-alpha',
  code: 'qwen/qwen3.8-27b:free',
  security: 'nvidia/nemotron-3.5-lightning:free',
  ultra: 'nvidia/nemotron-3-ultra-550b-a55b:free',
  arch: 'nvidia/nemotron-3.5-lightning:free',
  diff: 'cohere/north-mini-code:free',
  gemma: 'google/gemma-4-31b-it:free'
};

const FALLBACK_MODELS = [
  'openrouter/free',
  'stealth/space-bunny-alpha',
  'google/gemma-4-31b-it:free',
  'nvidia/nemotron-3-ultra-550b-a55b:free',
  'qwen/qwen3.8-27b:free'
];

const AUTH_PATH = path.join(process.env.USERPROFILE || process.env.HOME, '.local', 'share', 'opencode', 'auth.json');

function getApiKey() {
  if (process.env.OPENROUTER_API_KEY) return process.env.OPENROUTER_API_KEY;
  if (fs.existsSync(AUTH_PATH)) {
    try {
      const data = JSON.parse(fs.readFileSync(AUTH_PATH, 'utf8'));
      if (data?.openrouter?.key) return data.openrouter.key;
    } catch (_) {}
  }
  return null;
}

const AGENTS_DIR = path.resolve(__dirname, '..', '..', 'agency-agents');
const AGENT_MAP = {
  security: path.join(AGENTS_DIR, 'security-appsec-engineer.md'),
  appsec: path.join(AGENTS_DIR, 'security-appsec-engineer.md'),
  architect: path.join(AGENTS_DIR, 'engineering-backend-architect.md'),
  reviewer: path.join(AGENTS_DIR, 'engineering-code-reviewer.md')
};

function parseArgs() {
  const args = process.argv.slice(2);
  const options = {
    model: 'auto',
    agent: null,
    file: null,
    prompt: ''
  };

  for (let i = 0; i < args.length; i++) {
    if (args[i] === '-m' || args[i] === '--model') {
      options.model = args[++i];
    } else if (args[i] === '-a' || args[i] === '--agent') {
      options.agent = args[++i];
    } else if (args[i] === '-f' || args[i] === '--file') {
      options.file = args[++i];
    } else {
      options.prompt += (options.prompt ? ' ' : '') + args[i];
    }
  }

  return options;
}

function resolveModel(input) {
  return MODEL_ALIASES[input] || input;
}

function getSystemPrompt(agentKey) {
  if (!agentKey) return 'You are an expert Moodle LMS plugin architect and security auditor specializing in Moodle 4.x and 5.0.';
  const p = AGENT_MAP[agentKey];
  if (p && fs.existsSync(p)) {
    try {
      return fs.readFileSync(p, 'utf8');
    } catch (_) {}
  }
  return `You are a specialized agent: ${agentKey}.`;
}

function queryOpenRouter(apiKey, model, systemPrompt, userMessage) {
  return new Promise((resolve, reject) => {
    const payload = JSON.stringify({
      model: model,
      messages: [
        { role: 'system', content: systemPrompt },
        { role: 'user', content: userMessage }
      ]
    });

    const req = https.request({
      hostname: 'openrouter.ai',
      port: 443,
      path: '/api/v1/chat/completions',
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${apiKey}`,
        'HTTP-Referer': 'https://antigravity.ide',
        'X-Title': 'SANAD Lockdown Plugin OpenCode Adviser'
      }
    }, (res) => {
      let data = '';
      res.on('data', chunk => data += chunk);
      res.on('end', () => {
        if (res.statusCode >= 200 && res.statusCode < 300) {
          try {
            const parsed = JSON.parse(data);
            const content = parsed.choices?.[0]?.message?.content;
            if (content) resolve(content);
            else reject(new Error('No response content from model'));
          } catch (e) {
            reject(new Error('Failed to parse API response: ' + data));
          }
        } else {
          reject(new Error(`API Error ${res.statusCode}: ${data}`));
        }
      });
    });

    req.on('error', reject);
    req.write(payload);
    req.end();
  });
}

async function main() {
  const opts = parseArgs();
  const apiKey = getApiKey();

  if (!apiKey) {
    console.error('Error: OpenRouter API key not found in ~/.local/share/opencode/auth.json or OPENROUTER_API_KEY environment variable.');
    process.exit(1);
  }

  let userMsg = opts.prompt;
  if (opts.file) {
    if (!fs.existsSync(opts.file)) {
      console.error(`Error: File not found: ${opts.file}`);
      process.exit(1);
    }
    const fileContent = fs.readFileSync(opts.file, 'utf8');
    userMsg = `[File: ${opts.file}]\n\`\`\`\n${fileContent}\n\`\`\`\n\nTask: ${userMsg || 'Review and advise on this file.'}`;
  }

  if (!userMsg) {
    console.log('Usage: npm run adviser -- [-m model] [-a agent] [-f file] "prompt"');
    console.log('Models: bunny, code, security, auto (default)');
    console.log('Agents: security, architect, reviewer');
    process.exit(0);
  }

  const systemPrompt = getSystemPrompt(opts.agent);
  const targetModel = resolveModel(opts.model);

  console.log(`\n🤖 Calling OpenCode Adviser [Model: ${targetModel}] [Agent: ${opts.agent || 'moodle-architect'}]...`);

  const modelsToTry = [targetModel, ...FALLBACK_MODELS.filter(m => m !== targetModel)];

  for (const m of modelsToTry) {
    try {
      const reply = await queryOpenRouter(apiKey, m, systemPrompt, userMsg);
      console.log(`\n=== Adviser Response (${m}) ===\n`);
      console.log(reply);
      return;
    } catch (err) {
      console.warn(`⚠️ Warning: Model ${m} failed: ${err.message}. Trying next available fallback...`);
    }
  }

  console.error('❌ Error: All fallback models exhausted.');
  process.exit(1);
}

main();
