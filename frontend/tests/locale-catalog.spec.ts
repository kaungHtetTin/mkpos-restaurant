import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import ts from 'typescript';
import { myMessages } from '../src/locales/my';
import { translateValidation } from '../src/locale-validation';
import { formatLocalizedDate } from '../src/locale-dates';

// Symbols, SI units and machine-readable reference numbers keep the same form in both languages.
const invariantMessages = new Set([
  ' ({value1})', ' · {value1}', '+ {value1}', '56 mm', '88 mm', ': {value1}',
  'G-{value1} · {value2}', 'STATEMENT-{value1}', 'mm', '{value1} {value2}',
  '{value1} · {value2}', '{value1} → {value2}', '{value1}, {value2}',
  // This branch is rendered only for the office application, outside the owner locale provider.
  'Opening platform office...',
]);

test('every owner translation lookup has a Myanmar catalog entry', () => {
  const missing: string[] = [];
  for (const filename of fs.readdirSync('src').filter(file => file.endsWith('.tsx') && !file.startsWith('Office') && file !== 'GuestMenu.tsx')) {
    const source = ts.createSourceFile(filename, fs.readFileSync(path.join('src', filename), 'utf8'), ts.ScriptTarget.Latest, true, ts.ScriptKind.TSX);
    const visit = (node: ts.Node) => {
      if (ts.isCallExpression(node) && node.expression.getText(source) === 'tr' && node.arguments[0] && ts.isStringLiteral(node.arguments[0])) {
        const key = node.arguments[0].text;
        if (!Object.hasOwn(myMessages, key) && !invariantMessages.has(key)) missing.push(`${filename}: ${key}`);
      }
      if (ts.isJsxText(node) && /[A-Za-z]/.test(node.text) && !['G-', 'English', 'EN'].includes(node.text.trim())) missing.push(`${filename}: unlocalized JSX text ${node.text.trim()}`);
      ts.forEachChild(node, visit);
    };
    visit(source);
  }
  expect(missing).toEqual([]);
});

test('readable owner literals are localized or explicitly identify code and stored payment data', () => {
  const missing: string[] = [];
  const ownerFiles = fs.readdirSync('src').filter(file => file.endsWith('.tsx') && !file.startsWith('Office') && !['GuestMenu.tsx', 'locale.tsx', 'PlatformLogo.tsx', 'PlatformBranding.tsx', 'main.tsx'].includes(file));
  const identifiers = new Set(['Escape', 'Tab', 'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End']);
  for (const filename of ownerFiles) {
    const source = ts.createSourceFile(filename, fs.readFileSync(path.join('src', filename), 'utf8'), ts.ScriptTarget.Latest, true, ts.ScriptKind.TSX);
    const visit = (node: ts.Node) => {
      if (ts.isStringLiteral(node) && (/^[A-Z][a-z]/.test(node.text) || /[A-Za-z] [A-Za-z]/.test(node.text))) {
        let code = false;
        for (let parent: ts.Node | undefined = node.parent; parent; parent = parent.parent) {
          if (ts.isTypeNode(parent) || ts.isImportDeclaration(parent)) code = true;
          if (ts.isJsxAttribute(parent) && ['className', 'type', 'name', 'value', 'autoComplete', 'inputMode', 'id', 'style', 'accept', 'role'].includes(parent.name.getText(source))) code = true;
        }
        const translated = ts.isCallExpression(node.parent) && node.parent.expression.getText(source) === 'tr';
        const technical = identifiers.has(node.text) || /^Asia\//.test(node.text);
        // Receipt snapshots retain the canonical cash marker for tender calculations; display goes through paymentMethodText.
        const cashMarker = node.text === 'Cash';
        const storedError = ts.isNewExpression(node.parent) && node.parent.expression.getText(source) === 'Error' && Object.hasOwn(myMessages, node.text);
        if (!code && !translated && !technical && !cashMarker && !storedError) missing.push(`${filename}: ${node.text}`);
      }
      ts.forEachChild(node, visit);
    };
    visit(source);
  }
  expect(missing).toEqual([]);
});

test('owner server messages and password recovery messages have translations', () => {
  const folders = ['../backend/app/Http/Controllers', '../backend/app/Models', '../backend/app/Support', '../backend/app/Http/Middleware'];
  const missing: string[] = [];
  for (const folder of folders) {
    for (const filename of fs.readdirSync(folder).filter(file => file.endsWith('.php') && !file.startsWith('Office') && !['PlatformLedger.php', 'ApplyPlatformBranding.php'].includes(file))) {
      const source = fs.readFileSync(path.join(folder, filename), 'utf8');
      const patterns = [
        /\b(?:abort_if|abort_unless|abort)\([\s\S]*?\b(?:400|401|403|404|409|413|422|429|500|503),\s*'((?:\\.|[^'\\])*)'/g,
        /'message'\s*=>\s*'((?:\\.|[^'\\])*)'/g,
      ];
      for (const pattern of patterns) {
        for (const match of source.matchAll(pattern)) {
          const key = match[1].replace(/\\'/g, "'").replace(/\\\\/g, '\\');
          if (/[A-Za-z]{3}/.test(key) && !Object.hasOwn(myMessages, key)) missing.push(`${filename}: ${key}`);
        }
      }
    }
  }
  expect(missing).toEqual([]);
});

test('validation localizes fields and constraints without dropping numeric limits', () => {
  const cases = [
    'The amount field must be at least 1.', 'The email field must be a valid email address.',
    'The name field is required.', 'The password field confirmation does not match.',
    'The selected category id is invalid.', 'The image field must not be greater than 5120 kilobytes.',
    'The entry date field must match the format Y-m-d.', 'The email has already been taken.',
  ];
  for (const message of cases) {
    const translated = translateValidation(message, myMessages);
    expect(translated, message).toMatch(/[\u1000-\u109f]/);
    expect(translated, message).not.toContain('The ');
    for (const limit of message.match(/\d+/g) ?? []) expect(translated, message).toContain(limit);
  }
});

test('Myanmar date formatting works without browser Myanmar ICU data and preserves business timezone', () => {
  const date = new Date('2026-12-31T20:00:00Z');
  expect(formatLocalizedDate(date, 'my', { timeZone: 'Asia/Yangon', year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit' })).toBe('1 ဇန်နဝါရီ 2027 02:30');
  expect(formatLocalizedDate(date, 'my', { timeZone: 'UTC', month: 'short' })).toBe('ဒီ');
  expect(formatLocalizedDate(date, 'en', { timeZone: 'UTC', day: 'numeric', month: 'short', year: 'numeric' })).toBe('31 Dec 2026');
});
