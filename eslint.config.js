import js from '@eslint/js'
import tsParser from '@typescript-eslint/parser'
import prettier from 'eslint-config-prettier'
import pluginVue from 'eslint-plugin-vue'
import { defineConfig, globalIgnores } from 'eslint/config'
import globals from 'globals'

export default defineConfig([
    globalIgnores(['**/dist/**', '**/node_modules/**', '**/vendor/**']),
    { files: ['**/*.{js,ts,vue}'] },
    js.configs.recommended,
    ...pluginVue.configs['flat/recommended'],
    {
        files: ['**/*.ts'],
        languageOptions: {
            parser: tsParser,
        },
        rules: {
            'no-unused-vars': 'off',
        },
    },
    {
        languageOptions: {
            sourceType: 'module',
            globals: {
                ...globals.browser,
                ...globals.node,
                Statamic: 'readonly',
                __: 'readonly',
                Fieldtype: 'readonly',
            },
        },
    },
    prettier,
])
