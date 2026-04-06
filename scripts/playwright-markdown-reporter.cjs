const fs = require('fs');
const path = require('path');

class PlaywrightMarkdownReporter {
  constructor(options = {}) {
    this.options = options;
    this.results = [];
    this.totalTests = 0;
    this.startTime = null;
    this.outputFile = options.outputFile || 'test-results/playwright-report.md';
  }

  onBegin(config, suite) {
    this.startTime = new Date();
    this.totalTests = suite.allTests().length;
    this.projectNames = (config.projects || []).map((project) => project.name);
  }

  onTestEnd(test, result) {
    const projectName = result.projectName || test.parent?.project()?.name || 'unknown';
    const titlePath = test.titlePath().slice(1);

    this.results.push({
      title: test.title,
      titlePath,
      file: test.location?.file || '',
      line: test.location?.line || 0,
      column: test.location?.column || 0,
      projectName,
      status: result.status,
      duration: result.duration,
      error: result.error?.message || '',
      retry: result.retry,
    });
  }

  async onEnd(fullResult) {
    const generatedAt = new Date();
    const summary = this.buildSummary(fullResult);
    const failedTests = this.results.filter((item) => item.status === 'failed' || item.status === 'timedOut');
    const flakyTests = this.results.filter((item) => item.status === 'flaky');
    const skippedTests = this.results.filter((item) => item.status === 'skipped');

    const lines = [
      '# Playwright 测试报告',
      '',
      `- 报告生成日期：${this.formatDate(generatedAt)}`,
      `- 整体结果：${fullResult.status}`,
      `- 启动时间：${this.formatDate(this.startTime)}`,
      `- 结束时间：${this.formatDate(generatedAt)}`,
      `- 总用例数：${this.totalTests}`,
      `- 通过：${summary.passed}`,
      `- 失败：${summary.failed}`,
      `- 超时：${summary.timedOut}`,
      `- 跳过：${summary.skipped}`,
      `- 中断：${summary.interrupted}`,
      `- 不稳定：${summary.flaky}`,
      '',
      '## 项目范围',
      '',
      ...this.projectNames.map((name) => `- ${name}`),
      '',
      '## 汇总说明',
      '',
      '- HTML 报告目录：`test-results/playwright-report-html/`',
      '- Markdown 报告文件：`test-results/playwright-report.md`',
      '- 测试中间产物目录：`test-artifacts/`',
      '',
    ];

    if (failedTests.length > 0) {
      lines.push('## 失败/超时用例', '');
      for (const item of failedTests) {
        lines.push(`### ${item.title}`);
        lines.push('');
        lines.push(`- 项目：${item.projectName}`);
        lines.push(`- 文件：\`${item.file}:${item.line}\``);
        lines.push(`- 状态：${item.status}`);
        lines.push(`- 耗时：${item.duration} ms`);
        if (item.error) {
          lines.push('- 错误信息：');
          lines.push('');
          lines.push('```text');
          lines.push(item.error.trim());
          lines.push('```');
        }
        lines.push('');
      }
    }

    if (flakyTests.length > 0) {
      lines.push('## 不稳定用例', '');
      for (const item of flakyTests) {
        lines.push(`- [${item.projectName}] ${item.title}（${item.file}:${item.line}）`);
      }
      lines.push('');
    }

    if (skippedTests.length > 0) {
      lines.push('## 跳过用例', '');
      for (const item of skippedTests) {
        lines.push(`- [${item.projectName}] ${item.title}（${item.file}:${item.line}）`);
      }
      lines.push('');
    }

    lines.push('## 全部用例结果', '');
    lines.push('| 项目 | 状态 | 用例 | 文件 | 耗时 |');
    lines.push('|---|---|---|---|---:|');

    for (const item of this.results) {
      lines.push(`| ${item.projectName} | ${item.status} | ${this.escapePipes(item.title)} | \`${item.file}:${item.line}\` | ${item.duration} ms |`);
    }

    lines.push('');

    const outputPath = path.resolve(process.cwd(), this.outputFile);
    fs.mkdirSync(path.dirname(outputPath), { recursive: true });
    fs.writeFileSync(outputPath, lines.join('\n'), 'utf8');
  }

  buildSummary(fullResult) {
    const summary = {
      passed: 0,
      failed: 0,
      timedOut: 0,
      skipped: 0,
      interrupted: 0,
      flaky: 0,
    };

    for (const item of this.results) {
      if (item.status === 'passed') summary.passed += 1;
      else if (item.status === 'failed') summary.failed += 1;
      else if (item.status === 'timedOut') summary.timedOut += 1;
      else if (item.status === 'skipped') summary.skipped += 1;
      else if (item.status === 'interrupted') summary.interrupted += 1;
      else if (item.status === 'flaky') summary.flaky += 1;
    }

    if (fullResult.status === 'timedout' && summary.timedOut === 0) {
      summary.timedOut = 1;
    }

    return summary;
  }

  formatDate(value) {
    if (!value) return 'N/A';
    return new Intl.DateTimeFormat('zh-CN', {
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
      hour12: false,
    }).format(value);
  }

  escapePipes(value) {
    return String(value).replace(/\|/g, '\\|');
  }
}

module.exports = PlaywrightMarkdownReporter;
