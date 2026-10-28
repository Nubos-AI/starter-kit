import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

export function appCss(): string {
    return readFileSync(
        resolve(process.cwd(), 'resources/css/app.css'),
        'utf8',
    );
}
