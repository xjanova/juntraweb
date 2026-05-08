@extends('layouts.app')
@section('title', 'MLM · ดวงดาราคอมมิชชั่น')

@push('head')
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/vis-network@9.1.9/standalone/umd/vis-network.min.js"></script>
  <link href="https://cdn.jsdelivr.net/npm/vis-network@9.1.9/dist/dist/vis-network.min.css" rel="stylesheet">
  <style>
    .mlm-shell {
      max-width: 1280px; margin: 0 auto; padding: 24px;
    }
    .kpi-grid {
      display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 16px; margin-bottom: 28px;
    }
    .kpi {
      background: linear-gradient(135deg, rgba(244,207,106,.08), rgba(176,122,255,.06));
      border: 1px solid rgba(244,207,106,.18);
      border-radius: 16px; padding: 20px 22px;
      backdrop-filter: blur(12px);
    }
    .kpi-label {
      font-size: 11px; letter-spacing: .22em; color: var(--gold, #f4cf6a);
      text-transform: uppercase; margin-bottom: 10px; font-family: var(--display);
    }
    .kpi-value {
      font-family: var(--display); font-size: 36px; font-weight: 600; color: var(--moon, #eadcc1);
      line-height: 1;
    }
    .kpi-sub {
      font-size: 12px; color: var(--ink-dim, #9d8fb1); margin-top: 6px;
    }
    .panel-mlm {
      background: rgba(255,255,255,.04);
      border: 1px solid rgba(255,255,255,.06);
      border-radius: 18px; padding: 24px;
    }
    .panel-mlm h3 {
      font-family: var(--display); font-size: 13px; letter-spacing: .22em;
      color: var(--gold, #f4cf6a); text-transform: uppercase; margin: 0 0 16px;
    }
    .grid-2 {
      display: grid; grid-template-columns: 1fr 1.2fr; gap: 24px; margin-bottom: 28px;
    }
    @media (max-width: 900px) { .grid-2 { grid-template-columns: 1fr; } }
    #earningsChart { max-height: 280px; }
    #networkTree {
      height: 420px; background: rgba(0,0,0,.25); border-radius: 12px;
      border: 1px solid rgba(255,255,255,.05);
    }
    .commission-table {
      width: 100%; border-collapse: collapse; font-size: 14px;
    }
    .commission-table th, .commission-table td {
      padding: 12px 14px; text-align: left;
      border-bottom: 1px solid rgba(255,255,255,.05);
    }
    .commission-table th {
      font-family: var(--display); font-size: 11px; letter-spacing: .12em;
      color: var(--gold, #f4cf6a); text-transform: uppercase; font-weight: 500;
    }
    .commission-table td { color: var(--ink, #eadcc1); }
    .commission-table tr:hover td { background: rgba(244,207,106,.04); }
    .badge {
      display: inline-block; padding: 3px 10px; border-radius: 99px;
      font-size: 11px; letter-spacing: .08em; text-transform: uppercase;
    }
    .badge-paid { background: rgba(80,200,120,.15); color: #9eddae; }
    .badge-approved { background: rgba(120,180,255,.15); color: #9ec6f5; }
    .badge-pending { background: rgba(244,207,106,.15); color: #f4cf6a; }
    .badge-rejected { background: rgba(244,80,80,.15); color: #f59999; }
    .pager {
      display: flex; gap: 6px; justify-content: flex-end; margin-top: 16px;
    }
    .pager button {
      background: rgba(244,207,106,.08); border: 1px solid rgba(244,207,106,.2);
      color: var(--ink, #eadcc1); padding: 6px 12px; border-radius: 6px; cursor: pointer;
      font-family: inherit; font-size: 13px;
    }
    .pager button:disabled { opacity: .4; cursor: not-allowed; }
    .pager button.active { background: var(--gold, #f4cf6a); color: #1a0d2e; }
    .empty {
      text-align: center; padding: 40px; color: var(--ink-dim, #9d8fb1); font-size: 14px;
    }
    .target-picker {
      display: flex; gap: 12px; align-items: center; margin-bottom: 24px;
      padding: 14px 18px; background: rgba(244,207,106,.06);
      border: 1px solid rgba(244,207,106,.2); border-radius: 12px;
    }
    .target-picker select {
      flex: 1; max-width: 360px; background: rgba(0,0,0,.35);
      border: 1px solid rgba(255,255,255,.12); color: var(--ink, #eadcc1);
      padding: 8px 12px; border-radius: 6px; font-family: inherit;
    }
  </style>
@endpush

@section('content')
<section class="canvas" style="padding-top: 140px;">
  <div class="mlm-shell" x-data="mlmDashboard({
        viewingSelf: {{ $viewingSelf ? 'true' : 'false' }},
        isAdmin: {{ $isAdmin ? 'true' : 'false' }},
        treeData: @js($tree),
        commissionsInitial: @js($commissions),
      })">

    <div style="margin-bottom: 28px;">
      <div class="eyebrow" style="display: inline-flex;">ระบบสายงาน · MLM Dashboard</div>
      <h1 class="display" style="font-size: clamp(40px, 5vw, 68px); margin: 8px 0 4px;">
        ผังทีม <em>ดูดวง</em>
      </h1>
      <p class="lede" style="margin: 0; max-width: 720px;">
        ข้อมูลสด ๆ จาก Thaiprompt — เห็นทุกบิลดูดวง คำนวณเป็นคอมมิชชั่นในทีมของคุณ
        @if (!empty($stats['user']['name']))
          · กำลังดูข้อมูลของ <em style="color: var(--gold);">{{ $stats['user']['name'] }}</em>
        @endif
      </p>
    </div>

    @if ($isAdmin)
      <div class="target-picker">
        <span style="font-size: 13px; color: var(--ink-dim);">ดูข้อมูลของ:</span>
        <select x-model="targetUserId" @change="switchTarget()">
          <option value="">— ตัวเอง —</option>
          <template x-for="u in adminUsers" :key="u.id">
            <option :value="u.id" x-text="`${u.name} (${u.email})`"></option>
          </template>
        </select>
        <button @click="loadAdminUsers()" style="background: rgba(255,255,255,.08); border: 0; color: var(--ink); padding: 8px 14px; border-radius: 6px; cursor: pointer; font-size: 13px;">
          🔄 โหลดรายชื่อ
        </button>
      </div>
    @endif

    {{-- ── KPI strip ────────────────────────────────────────── --}}
    <div class="kpi-grid">
      <div class="kpi">
        <div class="kpi-label">วันนี้</div>
        <div class="kpi-value">฿{{ number_format($stats['totals']['today'] ?? 0, 0) }}</div>
        <div class="kpi-sub">commission ที่เข้าวันนี้</div>
      </div>
      <div class="kpi">
        <div class="kpi-label">เดือนนี้</div>
        <div class="kpi-value">฿{{ number_format($stats['totals']['this_month'] ?? 0, 0) }}</div>
        <div class="kpi-sub">รวมทั้งเดือน</div>
      </div>
      <div class="kpi">
        <div class="kpi-label">ตลอดเวลา</div>
        <div class="kpi-value">฿{{ number_format($stats['totals']['all_time'] ?? 0, 0) }}</div>
        <div class="kpi-sub">{{ $stats['counts']['commissions_total'] ?? 0 }} รายการ · {{ $stats['counts']['unique_customers'] ?? 0 }} ลูกค้า</div>
      </div>
      <div class="kpi">
        <div class="kpi-label">ทีม</div>
        <div class="kpi-value">{{ $stats['mlm']['total_team_members'] ?? 0 }}</div>
        <div class="kpi-sub">ตรง {{ $stats['mlm']['direct_referrals'] ?? 0 }} คน · PV {{ number_format($stats['mlm']['total_team_pv'] ?? 0, 0) }}</div>
      </div>
    </div>

    {{-- ── Earnings chart + tree ────────────────────────────── --}}
    <div class="grid-2">
      <div class="panel-mlm">
        <h3>📈 รายได้ 12 เดือนล่าสุด</h3>
        <canvas id="earningsChart"></canvas>
      </div>
      <div class="panel-mlm">
        <h3>🌳 ผังทีม (downline)</h3>
        <div id="networkTree"></div>
        <div style="margin-top: 12px; font-size: 12px; color: var(--ink-dim);">
          คลิกที่จุดเพื่อดูรายละเอียด · ลากเพื่อจัดผัง
        </div>
      </div>
    </div>

    {{-- ── Commission table ────────────────────────────────── --}}
    <div class="panel-mlm">
      <h3>📋 ประวัติคอมมิชชั่น</h3>
      <div style="overflow-x: auto;">
        <table class="commission-table">
          <thead>
            <tr>
              <th>วันที่</th>
              <th>จากลูกค้า</th>
              <th>บิล</th>
              <th>ระดับ</th>
              <th style="text-align: right;">จำนวน</th>
              <th>สถานะ</th>
            </tr>
          </thead>
          <tbody>
            <template x-if="commissions.data.length === 0">
              <tr><td colspan="6"><div class="empty">ยังไม่มีคอมมิชชั่น</div></td></tr>
            </template>
            <template x-for="c in commissions.data" :key="c.id">
              <tr>
                <td x-text="formatDate(c.created_at)" style="white-space: nowrap;"></td>
                <td x-text="c.from_user?.name || c.reading?.customer || '—'"></td>
                <td x-text="c.reading?.id ? `#${c.reading.id}` : '—'" style="color: var(--ink-dim);"></td>
                <td>
                  <span x-show="c.level === 1" style="color: #9eddae;">สายตรง</span>
                  <span x-show="c.level === 2" style="color: #9ec6f5;">หลาน</span>
                </td>
                <td style="text-align: right; font-family: var(--display); font-weight: 600;"
                    x-text="`฿${Number(c.amount).toLocaleString()}`"></td>
                <td>
                  <span class="badge" :class="`badge-${c.status}`" x-text="statusLabel(c.status)"></span>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>

      <div class="pager" x-show="commissions.meta?.last_page > 1">
        <button @click="loadCommissions(1)" :disabled="commissions.meta.current_page === 1">«</button>
        <button @click="loadCommissions(commissions.meta.current_page - 1)" :disabled="commissions.meta.current_page === 1">‹</button>
        <span style="padding: 6px 14px; color: var(--ink-dim); font-size: 13px;"
              x-text="`หน้า ${commissions.meta.current_page} / ${commissions.meta.last_page}`"></span>
        <button @click="loadCommissions(commissions.meta.current_page + 1)" :disabled="commissions.meta.current_page === commissions.meta.last_page">›</button>
        <button @click="loadCommissions(commissions.meta.last_page)" :disabled="commissions.meta.current_page === commissions.meta.last_page">»</button>
      </div>
    </div>

    @if (empty($stats['user']))
      <div class="panel-mlm" style="margin-top: 28px; text-align: center;">
        <h3 style="margin-bottom: 14px;">ยังไม่ได้เชื่อม Thaiprompt</h3>
        <p style="color: var(--ink-dim); margin-bottom: 18px;">
          คุณต้องเข้าสู่ระบบด้วย Thaiprompt ก่อน — ระบบจึงดึงข้อมูล MLM ของคุณได้
        </p>
        <a href="{{ route('thaiprompt.redirect') }}" class="btn btn-primary">
          เข้าสู่ระบบด้วย Thaiprompt →
        </a>
      </div>
    @endif

  </div>
</section>

<script>
const monthlySeries = @js($stats['monthly_series'] ?? []);

document.addEventListener('alpine:init', () => {
  Alpine.data('mlmDashboard', (init) => ({
    viewingSelf: init.viewingSelf,
    isAdmin: init.isAdmin,
    targetUserId: '',
    adminUsers: [],
    commissions: init.commissionsInitial || { data: [], meta: { current_page: 1, last_page: 1 } },
    treeData: init.treeData,

    init() {
      this.$nextTick(() => {
        this.renderChart();
        this.renderTree();
      });
    },

    formatDate(iso) {
      if (!iso) return '—';
      const d = new Date(iso);
      return d.toLocaleDateString('th-TH', { day: '2-digit', month: 'short', year: '2-digit' });
    },

    statusLabel(s) {
      return { paid: 'จ่ายแล้ว', approved: 'อนุมัติ', pending: 'รอ', rejected: 'ปฏิเสธ' }[s] || s;
    },

    renderChart() {
      const ctx = document.getElementById('earningsChart');
      if (!ctx || !window.Chart) return;
      new Chart(ctx, {
        type: 'line',
        data: {
          labels: monthlySeries.map(m => m.label),
          datasets: [{
            data: monthlySeries.map(m => m.amount),
            borderColor: '#f4cf6a',
            backgroundColor: 'rgba(244,207,106,.15)',
            fill: true,
            tension: 0.35,
            pointBackgroundColor: '#f4cf6a',
            pointBorderColor: '#1a0d2e',
            pointBorderWidth: 2,
            pointRadius: 4,
          }]
        },
        options: {
          responsive: true, maintainAspectRatio: false,
          plugins: { legend: { display: false } },
          scales: {
            x: { ticks: { color: '#9d8fb1' }, grid: { color: 'rgba(255,255,255,.04)' } },
            y: { ticks: { color: '#9d8fb1', callback: v => '฿' + v.toLocaleString() }, grid: { color: 'rgba(255,255,255,.04)' } }
          }
        }
      });
    },

    renderTree() {
      const el = document.getElementById('networkTree');
      if (!el || !window.vis || !this.treeData?.tree) return;

      const nodes = []; const edges = [];
      const walk = (n, parentId = null) => {
        if (!n) return;
        nodes.push({
          id: n.id,
          label: `${n.name}\n฿${Number(n.fortune_commission || 0).toLocaleString()}`,
          color: {
            background: parentId === null ? '#f4cf6a' : 'rgba(244,207,106,.15)',
            border: '#f4cf6a',
            highlight: { background: '#fde08c', border: '#f4cf6a' }
          },
          font: { color: parentId === null ? '#1a0d2e' : '#eadcc1', size: 12, multi: 'html' },
          shape: 'box',
          margin: 12,
          borderWidth: parentId === null ? 2 : 1,
        });
        if (parentId !== null) {
          edges.push({ from: parentId, to: n.id, color: 'rgba(244,207,106,.4)', smooth: { type: 'continuous' } });
        }
        (n.children || []).forEach(c => walk(c, n.id));
      };
      walk(this.treeData.tree);

      new vis.Network(el, { nodes, edges }, {
        layout: { hierarchical: { direction: 'UD', sortMethod: 'directed', levelSeparation: 110, nodeSpacing: 140 } },
        physics: false,
        interaction: { hover: true, dragNodes: false, zoomView: true, dragView: true },
        edges: { arrows: { to: { enabled: true, scaleFactor: .5 } } },
      });
    },

    async loadCommissions(page) {
      const url = new URL(@js(route('mlm.commissions')), window.location.origin);
      if (this.targetUserId) url.searchParams.set('user_id', this.targetUserId);
      url.searchParams.set('page', page);
      const r = await fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
      this.commissions = await r.json();
    },

    async loadAdminUsers() {
      const r = await fetch(@js(route('mlm.users')), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
      const j = await r.json();
      this.adminUsers = j.data || [];
    },

    switchTarget() {
      const url = new URL(window.location.href);
      if (this.targetUserId) url.searchParams.set('user_id', this.targetUserId);
      else url.searchParams.delete('user_id');
      window.location.href = url.toString();
    },
  }));
});
</script>
@endsection
