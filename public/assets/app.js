(function () {
  'use strict';

  var form = document.getElementById('query-form');
  var resultBox = document.getElementById('result');
  var btn = document.getElementById('submit-btn');

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  function renderError(msg) {
    resultBox.hidden = false;
    resultBox.innerHTML = '<div class="result-error">' + esc(msg) + '</div>';
  }

  function renderData(d) {
    var availClass = d.available > 0 ? '' : ' zero';
    var rows = [
      ['课程包', esc(d.package)],
      ['激活状态', '<span class="' + esc(d.status === 'active' ? 'badge badge-ok' : 'badge badge-warn') + '">' + esc(d.status_label) + '</span>'],
      ['绑定手机', esc(d.bound_phone || '—')],
      ['过期时间', esc(d.expires_text)],
      ['总次数', esc(d.total_times) + ' 次'],
      ['已使用', esc(d.used_times) + ' 次'],
      ['可用次数', '<span class="times-num' + availClass + '">' + esc(d.available) + '</span> 次']
    ];
    var html = '<div class="card result-card">'
      + '<div class="result-head"><h2>查询结果</h2>'
      + '<span class="badge ' + (d.available > 0 ? 'badge-ok' : 'badge-muted') + '">'
      + (d.available > 0 ? '可使用' : '暂不可用') + '</span></div>'
      + '<div class="result-rows">';
    rows.forEach(function (r) {
      html += '<div class="result-row"><span class="k">' + r[0] + '</span><span class="v">' + r[1] + '</span></div>';
    });
    html += '</div></div>';
    resultBox.innerHTML = html;
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var code = document.getElementById('code').value.trim();
    var phone = document.getElementById('phone').value.trim();

    if (!code) { renderError('请输入兑换码'); return; }
    if (!/^1[3-9]\d{9}$/.test(phone)) { renderError('请输入正确的 11 位手机号'); return; }

    btn.disabled = true;
    btn.textContent = '查询中…';
    resultBox.hidden = true;

    fetch('api/query.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ code: code, phone: phone })
    })
      .then(function (r) { return r.json().then(function (j) { return { status: r.status, body: j }; }); })
      .then(function (res) {
        if (res.body && res.body.ok) {
          renderData(res.body.data);
        } else {
          renderError((res.body && res.body.message) || '查询失败，请稍后再试');
        }
      })
      .catch(function () {
        renderError('网络异常，请稍后再试');
      })
      .finally(function () {
        btn.disabled = false;
        btn.textContent = '立即查询';
      });
  });
})();
