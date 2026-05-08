@extends('layouts.app')
@section('title', 'MLM · ผังทีมดูดวง')

@push('head')
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/vis-network@9.1.9/standalone/umd/vis-network.min.js"></script>
@endpush

@section('content')
<section class="canvas" style="padding-top: 140px">
  <div style="max-width:1280px;margin:0 auto" x-data="mlmDashboard({
        viewingSelf: {{ $viewingSelf ? 'true' : 'false' }},
        isAdmin: {{ $isAdmin ? 'true' : 'false' }},
        treeData: @js($tree),
        commissionsInitial: @js($commissions),
      })">

    <div style="margin-bottom: 32px">
      <div class="eyebrow" style="display:inline-flex">ระบบสายงาน · MLM DASHBOARD</div>
      <h1 class="display" style="font-size:clamp(40px,5vw,68px);margin:6px 0 12px">
        ผังทีม <em>ดูดวง</em>
      </h1>
      <p class="lede" style="margin:0">
        ข้อมูลสด ๆ จาก Thaiprompt — เห็นทุกบิลดูดวง คำนวณเป็นคอมมิชชั่นในทีมของคุณ
        @if (!empty($stats['user']['name']))
          · ของ <em style="color:var(--gold);font-style:normal">{{ $stats['user']['name'] }}</em>
        @endif
      </p>
    </div>

    @if (empty($stats))
      <div class="flash flash-error" style="margin-bottom:28px">
        ⚠️ ยังเชื่อมต่อ Thaiprompt ไม่ได้ — ตัวเลขด้านล่างอาจไม่ใช่ข้อมูลจริง
        <a href="{{ route('filament.admin.pages.membership-integration', [], false) }}" style="margin-left:8px;color:var(--gold);text-decoration:underline">ตรวจการตั้งค่า →</a>
      </div>
    @endif

    @if ($isAdmin)
      <div class="panel" style="padding:18px 22px;margin-bottom:24px;display:flex;gap:14px;align-items:center;flex-wrap:wrap">
        <span style="font-family:var(--display);font-size:11px;letter-spacing:.22em;color:var(--gold);text-transform:uppercase">ดูข้อมูลของ:</span>
        <select x-model="targetUserId" @change="switchTarget()"
                style="flex:1;min-width:240px;max-width:380px;padding:10px 14px;background:rgba(7,4,26,.6);border:1px solid var(--line);color:var(--ink);border-radius:10px;font-family:var(--thai);font-size:14px;outline:none">
          <option value="">— ตัวเอง —</option>
          <template x-for="u in adminUsers" :key="u.id">
            <option :value="u.id" x-text="`${u.name} (${u.email})`"></option>
          </template>
        </select>
        <button @click="loadAdminUsers()" class="btn btn-ghost" style="padding:10px 18px;font-size:11px">
          🔄 โหลดรายชื่อ
        </button>
      </div>
    @endif

    {{-- ── KPI strip ────────────────────────────────────────── --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:18px;margin-bottom:32px">
      <div class="panel" style="padding:24px 26px">
        <div class="eyebrow" style="display:inline-flex;margin-bottom:10px">วันนี้</div>
        <div style="font-family:var(--display);font-size:36px;font-weight:600;color:var(--moon);line-height:1">฿{{ number_format($stats['totals']['today'] ?? 0, 0) }}</div>
        <div style="font-size:12px;color:var(--ink-dim);margin-top:6px">commission ที่เข้าวันนี้</div>
      </div>
      <div class="panel" style="padding:24px 26px">
        <div class="eyebrow" style="display:inline-flex;margin-bottom:10px">เดือนนี้</div>
        <div style="font-family:var(--display);font-size:36px;font-weight:600;color:var(--moon);line-height:1">฿{{ number_format($stats['totals']['this_month'] ?? 0, 0) }}</div>
        <div style="font-size:12px;color:var(--ink-dim);margin-top:6px">รวมทั้งเดือน</div>
      </div>
      <div class="panel" style="padding:24px 26px">
        <div class="eyebrow" style="display:inline-flex;margin-bottom:10px">ตลอดเวลา</div>
        <div style="font-family:var(--display);font-size:36px;font-weight:600;color:var(--moon);line-height:1">฿{{ number_format($stats['totals']['all_time'] ?? 0, 0) }}</div>
        <div style="font-size:12px;color:var(--ink-dim);margin-top:6px">{{ $stats['counts']['commissions_total'] ?? 0 }} รายการ · {{ $stats['counts']['unique_customers'] ?? 0 }} ลูกค้า</div>
      </div>
      <div class="panel" style="padding:24px 26px">
        <div class="eyebrow" style="display:inline-flex;margin-bottom:10px">ทีม</div>
        <div style="font-family:var(--display);font-size:36px;font-weight:600;color:var(--moon);line-height:1">{{ $stats['mlm']['total_team_members'] ?? 0 }}</div>
        <div style="font-size:12px;color:var(--ink-dim);margin-top:6px">ตรง {{ $stats['mlm']['direct_referrals'] ?? 0 }} คน · PV {{ number_format($stats['mlm']['total_team_pv'] ?? 0, 0) }}</div>
      </div>
    </div>

    {{-- ── Earnings + Tree ──────────────────────────────────── --}}
    <div style="display:grid;grid-template-columns:1fr 1.2fr;gap:24px;margin-bottom:32px" class="mlm-two-col">
      <div class="panel" style="padding:28px">
        <div class="eyebrow" style="display:inline-flex;margin-bottom:18px">รายได้ 12 เดือนล่าสุด</div>
        <canvas id="earningsChart" style="max-height:280px"></canvas>
      </div>
      <div class="panel" style="padding:28px">
        <div class="eyebrow" style="display:inline-flex;margin-bottom:18px">ผังทีม (DOWNLINE)</div>
        <div id="networkTree" style="height:380px;background:rgba(7,4,26,.5);border-radius:12px;border:1px solid var(--line-soft)"></div>
        <div style="margin-top:12px;font-size:12px;color:var(--ink-faint);letter-spacing:.04em">
          คลิกที่จุดเพื่อดูรายละเอียด · ลากเพื่อจัดผัง
        </div>
      </div>
    </div>

    {{-- ── Commission table ────────────────────────────────── --}}
    <div class="panel" style="padding:28px">
      <div class="eyebrow" style="display:inline-flex;margin-bottom:18px">ประวัติคอมมิชชั่น</div>
      <div style="overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;font-size:14px">
          <thead>
            <tr>
              @foreach (['วันที่','จากลูกค้า','บิล','ระดับ','จำนวน','สถานะ'] as $h)
                <th style="padding:12px 14px;text-align:{{ $h === 'จำนวน' ? 'right' : 'left' }};font-family:var(--display);font-size:11px;letter-spacing:.18em;color:var(--gold);text-transform:uppercase;font-weight:500;border-bottom:1px solid var(--line-soft)">{{ $h }}</th>
              @endforeach
            </tr>
          </thead>
          <tbody>
            <template x-if="commissions.data.length === 0">
              <tr><td colspan="6" style="padding:40px;text-align:center;color:var(--ink-faint);font-size:14px">
                @if (empty($stats)) ไม่สามารถโหลดข้อมูลจาก Thaiprompt ได้
                @else ยังไม่มีคอมมิชชั่น @endif
              </td></tr>
            </template>
            <template x-for="c in commissions.data" :key="c.id">
              <tr style="transition:background .15s">
                <td style="padding:14px;color:var(--ink);border-bottom:1px solid var(--line-soft);white-space:nowrap" x-text="formatDate(c.created_at)"></td>
                <td style="padding:14px;color:var(--ink);border-bottom:1px solid var(--line-soft)" x-text="c.from_user?.name || c.reading?.customer || '—'"></td>
                <td style="padding:14px;color:var(--ink-dim);border-bottom:1px solid var(--line-soft)" x-text="c.reading?.id ? `#${c.reading.id}` : '—'"></td>
                <td style="padding:14px;border-bottom:1px solid var(--line-soft)">
                  <span x-show="c.level === 1" style="color:var(--gold)">สายตรง</span>
                  <span x-show="c.level === 2" style="color:#9ec6f5">หลาน</span>
                </td>
                <td style="padding:14px;text-align:right;font-family:var(--display);font-weight:600;color:var(--moon);border-bottom:1px solid var(--line-soft)"
                    x-text="`฿${Number(c.amount).toLocaleString()}`"></td>
                <td style="padding:14px;border-bottom:1px solid var(--line-soft)">
                  <span :style="badgeStyle(c.status)" x-text="statusLabel(c.status)"
                        style="display:inline-block;padding:3px 12px;border-radius:99px;font-family:var(--display);font-size:10px;letter-spacing:.1em;text-transform:uppercase"></span>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>

      <div x-show="commissions.meta?.last_page > 1" style="display:flex;gap:6px;justify-content:flex-end;margin-top:18px">
        <button @click="loadCommissions(1)" :disabled="commissions.meta.current_page === 1" class="btn btn-ghost" style="padding:6px 12px;font-size:11px">«</button>
        <button @click="loadCommissions(commissions.meta.current_page - 1)" :disabled="commissions.meta.current_page === 1" class="btn btn-ghost" style="padding:6px 12px;font-size:11px">‹</button>
        <span style="padding:6px 16px;color:var(--ink-dim);font-size:13px;font-family:var(--display);letter-spacing:.06em"
              x-text="`หน้า ${commissions.meta.current_page} / ${commissions.meta.last_page}`"></span>
        <button @click="loadCommissions(commissions.meta.current_page + 1)" :disabled="commissions.meta.current_page === commissions.meta.last_page" class="btn btn-ghost" style="padding:6px 12px;font-size:11px">›</button>
        <button @click="loadCommissions(commissions.meta.last_page)" :disabled="commissions.meta.current_page === commissions.meta.last_page" class="btn btn-ghost" style="padding:6px 12px;font-size:11px">»</button>
      </div>
    </div>

    @if (empty($stats['user']))
      <div class="panel" style="margin-top:28px;text-align:center;padding:36px">
        <div class="eyebrow" style="display:inline-flex;margin-bottom:14px">ยังไม่ได้เชื่อม THAIPROMPT</div>
        <p class="lede" style="margin:0 auto 18px">
          คุณต้องเข้าสู่ระบบด้วย Thaiprompt ก่อน — ระบบจึงดึงข้อมูล MLM ของคุณได้
        </p>
        <a href="{{ route('thaiprompt.redirect') }}" class="btn btn-primary">
          เข้าสู่ระบบด้วย Thaiprompt
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
        </a>
      </div>
    @endif

  </div>
</section>

<style>
  /* Just one breakpoint override — everything else uses theme classes/vars */
  @media (max-width: 900px) {
    .mlm-two-col { grid-template-columns: 1fr !important; }
  }
</style>

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

    init() { this.$nextTick(() => { this.renderChart(); this.renderTree(); }); },

    formatDate(iso) {
      if (!iso) return '—';
      const d = new Date(iso);
      return d.toLocaleDateString('th-TH', { day: '2-digit', month: 'short', year: '2-digit' });
    },
    statusLabel(s) { return { paid: 'จ่ายแล้ว', approved: 'อนุมัติ', pending: 'รอ', rejected: 'ปฏิเสธ' }[s] || s; },
    badgeStyle(s) {
      // Pull badge colors from theme variables — gold for in-progress, muted rose for rejected, soft green for paid.
      const map = {
        paid:     'background:rgba(120,200,120,.18);color:#9eddae;border:1px solid rgba(120,200,120,.35)',
        approved: 'background:rgba(120,180,255,.18);color:#9ec6f5;border:1px solid rgba(120,180,255,.35)',
        pending:  'background:linear-gradient(135deg,rgba(244,207,106,.2),rgba(244,207,106,.04));color:var(--gold);border:1px solid var(--line)',
        rejected: 'background:rgba(255,143,212,.18);color:#f59999;border:1px solid rgba(255,143,212,.35)',
      };
      return map[s] || map.pending;
    },

    renderChart() {
      const ctx = document.getElementById('earningsChart');
      if (!ctx || !window.Chart) return;
      const gold = getComputedStyle(document.documentElement).getPropertyValue('--gold').trim() || '#f4cf6a';
      const moonDim = 'rgba(246,239,224,.55)';
      new Chart(ctx, {
        type: 'line',
        data: {
          labels: monthlySeries.map(m => m.label),
          datasets: [{
            data: monthlySeries.map(m => m.amount),
            borderColor: gold,
            backgroundColor: 'rgba(244,207,106,.15)',
            fill: true,
            tension: 0.35,
            pointBackgroundColor: gold,
            pointBorderColor: '#1a0d3d',
            pointBorderWidth: 2,
            pointRadius: 4,
          }]
        },
        options: {
          responsive: true, maintainAspectRatio: false,
          plugins: { legend: { display: false } },
          scales: {
            x: { ticks: { color: moonDim }, grid: { color: 'rgba(244,207,106,.05)' } },
            y: { ticks: { color: moonDim, callback: v => '฿' + v.toLocaleString() }, grid: { color: 'rgba(244,207,106,.05)' } }
          }
        }
      });
    },

    renderTree() {
      const el = document.getElementById('networkTree');
      if (!el || !window.vis || !this.treeData?.tree) return;
      const gold = getComputedStyle(document.documentElement).getPropertyValue('--gold').trim() || '#f4cf6a';
      const ink  = getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() || '#f6efe0';

      const nodes = []; const edges = [];
      const walk = (n, parentId = null) => {
        if (!n) return;
        const isRoot = parentId === null;
        nodes.push({
          id: n.id,
          label: `${n.name}\n฿${Number(n.fortune_commission || 0).toLocaleString()}`,
          color: {
            background: isRoot ? gold : 'rgba(244,207,106,.15)',
            border: gold,
            highlight: { background: '#fde08c', border: gold }
          },
          font: { color: isRoot ? '#1a0d3d' : ink, size: 12, multi: 'html', face: "'Cormorant Garamond', 'Prompt', serif" },
          shape: 'box',
          margin: 12,
          borderWidth: isRoot ? 2 : 1,
        });
        if (!isRoot) {
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
