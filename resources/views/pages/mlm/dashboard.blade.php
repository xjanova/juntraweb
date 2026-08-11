@extends('layouts.app')
@section('page-handles-flash', '1')
@section('title', 'Affiliate · ผังสายงานดูดวง')

@push('head')
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
@endpush

@php
  // Display exactly what Thaiprompt sent — whole numbers stay whole,
  // fractional satang shows 2 dp. No web-side re-math, ever.
  $money = fn ($v) => number_format((float) $v, fmod((float) $v, 1.0) == 0.0 ? 0 : 2);

  // Referral link — same fallback chain as the mobile app so both surfaces
  // always show the identical invite URL.
  $refUser = (array) ($stats['user'] ?? []);
  $refCode = $refUser['referral_code'] ?? $refUser['code'] ?? $refUser['username'] ?? null;
  $refCode = is_string($refCode) ? substr(preg_replace('/[^A-Za-z0-9_-]/', '', $refCode), 0, 64) : null;
  $refCode = ($refCode !== null && $refCode !== '') ? $refCode : null;
  $refUrl  = $refCode ? route('referral', ['code' => $refCode]) : null;
@endphp

@section('content')
<section class="canvas" style="padding-top: 140px">
  <div style="max-width:1280px;margin:0 auto" x-data="mlmDashboard({
        viewingSelf: {{ $viewingSelf ? 'true' : 'false' }},
        isAdmin: {{ $isAdmin ? 'true' : 'false' }},
        treeData: @js($tree),
        commissionsInitial: @js($commissions),
        fetchedAt: @js($fetchedAt),
        refUrl: @js($refUrl),
      })">

    {{-- ── Header ───────────────────────────────────────────── --}}
    <x-page-hero art="images/juntra/art/mlm.webp" />

    <div style="margin-bottom: 26px">
      <div class="eyebrow" style="display:inline-flex">ระบบสายงาน · AFFILIATE</div>
      <h1 class="display" style="font-size:clamp(40px,5vw,68px);margin:6px 0 12px">
        สายงาน <em>พยากรณ์</em>
      </h1>
      <p class="lede" style="margin:0">
        ยอดทุกบาทดึงตรงจาก Thaiprompt — เว็บ แอพ และต้นทางเห็นตัวเลขชุดเดียวกัน
        @if (!empty($stats['user']['name']))
          · ของ <em style="color:var(--gold);font-style:normal">{{ $stats['user']['name'] }}</em>
        @endif
      </p>
    </div>

    @if (session('status'))
      <div class="flash" style="margin-bottom:20px">✓ {{ session('status') }}</div>
    @endif

    @if (empty($stats))
      <div class="flash flash-error" style="margin-bottom:20px">
        ⚠️ ยังเชื่อมต่อ Thaiprompt ไม่ได้ — ตัวเลขด้านล่างอาจไม่ใช่ข้อมูลจริง
        <a href="{{ route('filament.admin.pages.membership-integration', [], false) }}" style="margin-left:8px;color:var(--gold);text-decoration:underline">ตรวจการตั้งค่า →</a>
      </div>
    @endif

    {{-- ── Sync toolbar: live badge + refresh ───────────────── --}}
    <div class="panel mlm-sync" style="padding:14px 20px;margin-bottom:24px;display:flex;gap:14px;align-items:center;flex-wrap:wrap">
      <span class="mlm-live-dot"></span>
      <div style="flex:1;min-width:220px">
        <div style="font-size:13px;color:var(--ink)">
          ยอดตรงกับ Thaiprompt
          <span style="color:var(--ink-faint)">· ไม่มีการคำนวณซ้ำฝั่งเว็บ</span>
        </div>
        <div style="font-size:11.5px;color:var(--ink-faint);margin-top:2px" x-show="fetchedAt">
          ข้อมูล ณ <span style="color:var(--ink-dim)" x-text="formatFetched()"></span>
        </div>
      </div>
      <form method="POST" action="{{ route('mlm.refresh') }}" @submit="refreshing = true">
        @csrf
        @if (!$viewingSelf && $targetId)
          <input type="hidden" name="user_id" value="{{ $targetId }}">
        @endif
        <button type="submit" class="btn btn-ghost" style="padding:10px 20px;font-size:11px" :disabled="refreshing">
          <span x-show="!refreshing">⟳ ดึงยอดสด</span>
          <span x-show="refreshing" x-cloak>กำลังดึงจาก Thaiprompt…</span>
        </button>
      </form>
    </div>

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
    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:18px;margin-bottom:24px">
      <div class="panel mlm-kpi" style="padding:22px 24px">
        <div class="eyebrow" style="display:inline-flex;margin-bottom:10px">วันนี้</div>
        <div class="mlm-kpi-num">฿{{ $money($stats['totals']['today'] ?? 0) }}</div>
        <div style="font-size:12px;color:var(--ink-dim);margin-top:6px">commission ที่เข้าวันนี้</div>
      </div>
      <div class="panel mlm-kpi" style="padding:22px 24px">
        <div class="eyebrow" style="display:inline-flex;margin-bottom:10px">เดือนนี้</div>
        <div class="mlm-kpi-num">฿{{ $money($stats['totals']['this_month'] ?? 0) }}</div>
        <div style="font-size:12px;color:var(--ink-dim);margin-top:6px">รวมทั้งเดือน</div>
      </div>
      <div class="panel mlm-kpi" style="padding:22px 24px">
        <div class="eyebrow" style="display:inline-flex;margin-bottom:10px">ตลอดเวลา</div>
        <div class="mlm-kpi-num">฿{{ $money($stats['totals']['all_time'] ?? 0) }}</div>
        <div style="font-size:12px;color:var(--ink-dim);margin-top:6px">{{ $stats['counts']['commissions_total'] ?? 0 }} รายการ · {{ $stats['counts']['unique_customers'] ?? 0 }} ลูกค้า</div>
      </div>
      <div class="panel mlm-kpi" style="padding:22px 24px">
        <div class="eyebrow" style="display:inline-flex;margin-bottom:10px">ทีม</div>
        <div class="mlm-kpi-num">{{ $stats['mlm']['total_team_members'] ?? 0 }}</div>
        <div style="font-size:12px;color:var(--ink-dim);margin-top:6px">ตรง {{ $stats['mlm']['direct_referrals'] ?? 0 }} คน · PV {{ number_format((float) ($stats['mlm']['total_team_pv'] ?? 0)) }}</div>
      </div>
    </div>

    {{-- ── Earnings chart + Referral box ────────────────────── --}}
    <div class="mlm-two-col" style="display:grid;grid-template-columns:1.4fr 1fr;gap:24px;margin-bottom:24px">
      <div class="panel" style="padding:28px">
        <div class="eyebrow" style="display:inline-flex;margin-bottom:18px">รายได้ 12 เดือนล่าสุด</div>
        <div style="position:relative;height:280px">
          <canvas id="earningsChart"></canvas>
        </div>
      </div>

      <div class="panel" style="padding:28px;display:flex;flex-direction:column">
        <div class="eyebrow" style="display:inline-flex;margin-bottom:18px">ชวนเพื่อน · ต่อสายงาน</div>
        @if ($refUrl)
          <div style="display:flex;gap:18px;align-items:flex-start;flex-wrap:wrap">
            <div style="background:#fdf8ec;border-radius:14px;padding:10px;line-height:0" title="สแกนเพื่อเปิดลิงก์ชวนเพื่อน">
              <div id="refQr" style="width:112px;height:112px"></div>
            </div>
            <div style="flex:1;min-width:200px">
              <div style="font-size:12px;color:var(--ink-faint);margin-bottom:6px">รหัสของคุณ</div>
              <div style="font-family:var(--display);font-size:20px;letter-spacing:.14em;color:var(--gold);margin-bottom:12px">{{ $refCode }}</div>
              <div style="display:flex;align-items:center;gap:8px;background:rgba(7,4,26,.6);border:1px solid var(--line-soft);border-radius:10px;padding:10px 12px">
                <span style="flex:1;font-size:12.5px;color:var(--ink);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-family:monospace">{{ $refUrl }}</span>
                <button @click="copyRef()" class="btn btn-ghost" style="padding:6px 12px;font-size:10px;white-space:nowrap">
                  <span x-show="!copied">คัดลอก</span><span x-show="copied" x-cloak style="color:var(--gold)">✓ แล้ว</span>
                </button>
              </div>
              <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap">
                <a :href="`https://line.me/R/share?text=${encodeURIComponent('มาดูดวงกับฉันที่ จันทราพยากรณ์ ✨ ' + refUrl)}`"
                   target="_blank" rel="noopener" class="btn btn-ghost" style="padding:9px 16px;font-size:10px">แชร์ LINE</a>
                <a :href="`https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(refUrl)}`"
                   target="_blank" rel="noopener" class="btn btn-ghost" style="padding:9px 16px;font-size:10px">แชร์ FACEBOOK</a>
              </div>
            </div>
          </div>
          <p style="font-size:11.5px;color:var(--ink-faint);margin:14px 0 0;line-height:1.6">
            เพื่อนที่สมัครผ่านลิงก์นี้จะเข้าสายงานของคุณ — ทุกบิลดูดวงของทีมสร้างคอมมิชชั่นให้อัตโนมัติ
          </p>
        @else
          <div style="flex:1;display:flex;flex-direction:column;justify-content:center;align-items:center;text-align:center;gap:10px;padding:16px 0">
            <div style="font-size:34px">🔗</div>
            <div style="font-size:13.5px;color:var(--ink-dim);line-height:1.6;max-width:32ch">
              ยังไม่พบรหัสชวนเพื่อนจาก Thaiprompt — กด "ดึงยอดสด" หรือเชื่อมต่อบัญชีอีกครั้ง
            </div>
          </div>
        @endif
      </div>
    </div>

    {{-- ── Network chart (ผังสายงาน) ────────────────────────── --}}
    {{-- id: ปลายทางของลิงก์ "ทีมดูดวงของฉัน" จากเมนู Thaiprompt (ดู MlmController::users) --}}
    <div id="team" class="panel" style="padding:28px;margin-bottom:24px;scroll-margin-top:120px">
      <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:6px">
        <div class="eyebrow" style="display:inline-flex;margin:0">ผังสายงาน</div>
        <div style="flex:1"></div>

        {{-- view toggle --}}
        <div class="mlm-seg">
          <button :class="view === 'chart' && 'on'" @click="view = 'chart'">ผัง</button>
          <button :class="view === 'list' && 'on'" @click="view = 'list'">รายชื่อ</button>
        </div>

        <template x-if="view === 'chart' && hasTree">
          <div style="display:flex;gap:6px;align-items:center">
            <button class="mlm-ctl" @click="expandAll()" title="ขยายทุกชั้น">⊞</button>
            <button class="mlm-ctl" @click="collapseAll()" title="ย่อเหลือสายตรง">⊟</button>
            <span style="width:10px"></span>
            <button class="mlm-ctl" @click="zoomBy(-0.15)" title="ซูมออก">−</button>
            <button class="mlm-ctl" @click="zoomFit()" title="พอดีจอ" style="font-size:10px;width:auto;padding:0 10px" x-text="`${Math.round(zoom*100)}%`"></button>
            <button class="mlm-ctl" @click="zoomBy(0.15)" title="ซูมเข้า">+</button>
          </div>
        </template>
      </div>

      <div style="font-size:11.5px;color:var(--ink-faint);margin-bottom:16px">
        <template x-if="hasTree"><span>คลิกการ์ดเพื่อดูรายละเอียด · คลิกป้าย "ทีม" เพื่อย่อ/ขยายสาย · ลากเพื่อเลื่อนผัง</span></template>
      </div>

      {{-- legend --}}
      <div x-show="hasTree" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px">
        <span class="mlm-lg" style="--c:#f4cf6a">คุณ / สายตรง</span>
        <span class="mlm-lg" style="--c:#9ec6f5">ชั้น 2</span>
        <span class="mlm-lg" style="--c:#b07cff">ชั้น 3</span>
        <span class="mlm-lg" style="--c:#ff8fd4">ชั้น 4</span>
        <span class="mlm-lg" style="--c:#7ee0c3">ชั้น 5+</span>
      </div>

      {{-- chart view --}}
      <div x-show="view === 'chart'" style="position:relative">
        <template x-if="!hasTree">
          <div style="text-align:center;padding:56px 20px">
            <div style="font-size:44px;margin-bottom:12px">🌙</div>
            <div style="font-size:15px;color:var(--ink);margin-bottom:6px">ยังไม่มีคนในสายงาน</div>
            <div style="font-size:12.5px;color:var(--ink-faint)">แชร์ลิงก์ชวนเพื่อนด้านบน — คนที่สมัครผ่านลิงก์จะปรากฏบนผังนี้ทันที</div>
          </div>
        </template>
        <div x-show="hasTree" id="mlmViewport" class="mlm-viewport">
          <div id="mlmCanvas" class="mlm-canvas">
            <div id="mlmChart" class="mlm-chart"></div>
          </div>
        </div>

        {{-- node detail card --}}
        <div x-show="selected" x-cloak class="mlm-detail" @click.outside="selected = null">
          <button @click="selected = null" class="mlm-detail-x">✕</button>
          <div style="display:flex;gap:12px;align-items:center;margin-bottom:12px">
            <div class="mlm-detail-ava" :style="`--accent:${selected?.color}`" x-text="selected?.initial"></div>
            <div>
              <div style="font-size:15px;color:var(--ink);font-weight:600" x-text="selected?.name"></div>
              <div style="font-size:11px;color:var(--ink-faint)" x-text="selected?.levelLabel"></div>
            </div>
          </div>
          <div class="mlm-detail-grid">
            <div><b x-text="selected?.direct"></b><span>สายตรง</span></div>
            <div><b x-text="selected?.team"></b><span>ทีมทั้งหมด</span></div>
            <div><b x-text="`฿${selected?.commission}`"></b><span>คอมมิชชั่น</span></div>
          </div>
        </div>
      </div>

      {{-- list view --}}
      <div x-show="view === 'list'" x-cloak>
        <input type="search" x-model="searchQ" placeholder="🔍 ค้นหาชื่อในสายงาน…"
               style="width:100%;max-width:340px;margin-bottom:14px;padding:10px 14px;background:rgba(7,4,26,.6);border:1px solid var(--line-soft);color:var(--ink);border-radius:10px;font-family:var(--thai);font-size:13px;outline:none">
        <template x-if="filteredFlat().length === 0">
          <div style="padding:32px;text-align:center;color:var(--ink-faint);font-size:13px"
               x-text="flat.length === 0 ? 'ยังไม่มีคนในสายงาน' : 'ไม่พบชื่อที่ค้นหา'"></div>
        </template>
        <div>
          <template x-for="n in filteredFlat()" :key="n.key">
            <div class="mlm-row" :style="`padding-left:${14 + (searchQ ? 0 : (n.level - 1) * 26)}px`">
              <span class="mlm-row-guide" x-show="!searchQ && n.level > 1"></span>
              <div class="mlm-row-ava" :style="`--accent:${n.color}`" x-text="n.initial"></div>
              <div style="flex:1;min-width:0">
                <div style="font-size:13.5px;color:var(--ink);font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" x-text="n.name"></div>
                <div style="font-size:10.5px;color:var(--ink-faint)" x-text="`${n.levelLabel} · ตรง ${n.direct} · ทีม ${n.team}`"></div>
              </div>
              <div x-show="n.commissionRaw > 0" style="font-family:var(--display);font-size:13px;font-weight:600;color:var(--moon)" x-text="`฿${n.commission}`"></div>
            </div>
          </template>
        </div>
      </div>
    </div>

    {{-- ── Commission table ────────────────────────────────── --}}
    {{-- id: ปลายทางของลิงก์ "คอมมิชชั่นดูดวง" จากเมนู Thaiprompt (ดู MlmController::commissions) --}}
    <div id="commissions" class="panel" style="padding:28px;scroll-margin-top:120px">
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
          คุณต้องเข้าสู่ระบบด้วย Thaiprompt ก่อน — ระบบจึงดึงข้อมูลสายงานของคุณได้
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
  [x-cloak] { display: none !important; }

  @media (max-width: 900px) {
    .mlm-two-col { grid-template-columns: 1fr !important; }
  }

  .mlm-kpi { position: relative; overflow: hidden; }
  .mlm-kpi::after {
    content: ''; position: absolute; top: -30px; right: -30px; width: 110px; height: 110px;
    background: radial-gradient(circle, rgba(244,207,106,.12), transparent 70%); pointer-events: none;
  }
  .mlm-kpi-num { font-family: var(--display); font-size: 34px; font-weight: 600; color: var(--moon); line-height: 1; }

  .mlm-live-dot {
    width: 9px; height: 9px; border-radius: 50%; background: #7ee0c3; flex: none;
    box-shadow: 0 0 0 0 rgba(126,224,195,.5); animation: mlmPulse 2.2s infinite;
  }
  @keyframes mlmPulse {
    0% { box-shadow: 0 0 0 0 rgba(126,224,195,.45); }
    70% { box-shadow: 0 0 0 9px rgba(126,224,195,0); }
    100% { box-shadow: 0 0 0 0 rgba(126,224,195,0); }
  }

  /* segmented view toggle */
  .mlm-seg { display: inline-flex; background: rgba(7,4,26,.6); border: 1px solid var(--line-soft); border-radius: 999px; padding: 3px; }
  .mlm-seg button {
    padding: 7px 18px; border: none; background: transparent; color: var(--ink-dim); cursor: pointer;
    border-radius: 999px; font-family: var(--thai); font-size: 12.5px; transition: all .25s;
  }
  .mlm-seg button.on { background: linear-gradient(135deg, #f4cf6a, #c89a3c); color: #1a0d3d; font-weight: 600; }

  .mlm-ctl {
    width: 30px; height: 30px; border-radius: 8px; border: 1px solid var(--line-soft); cursor: pointer;
    background: rgba(7,4,26,.6); color: var(--ink-dim); font-size: 15px; line-height: 1; transition: all .2s;
    display: inline-flex; align-items: center; justify-content: center;
  }
  .mlm-ctl:hover { border-color: var(--gold); color: var(--gold); }

  .mlm-lg {
    display: inline-flex; align-items: center; gap: 6px; font-size: 10.5px; color: var(--ink-faint);
    padding: 3px 10px; border: 1px solid var(--line-soft); border-radius: 999px;
  }
  .mlm-lg::before { content: ''; width: 8px; height: 8px; border-radius: 50%; background: var(--c); }

  /* ── org chart ── */
  .mlm-viewport {
    overflow: auto; border-radius: 16px; border: 1px solid var(--line-soft);
    background:
      radial-gradient(1.5px 1.5px at 18% 30%, rgba(246,239,224,.25) 45%, transparent 55%),
      radial-gradient(1px 1px at 66% 12%, rgba(246,239,224,.2) 45%, transparent 55%),
      radial-gradient(1.5px 1.5px at 84% 64%, rgba(244,207,106,.25) 45%, transparent 55%),
      radial-gradient(1px 1px at 38% 78%, rgba(246,239,224,.18) 45%, transparent 55%),
      linear-gradient(180deg, rgba(7,4,26,.55), rgba(7,4,26,.75));
    max-height: 560px; min-height: 300px; cursor: grab; user-select: none;
  }
  .mlm-viewport.dragging { cursor: grabbing; }
  .mlm-canvas { transform-origin: top left; padding: 34px 40px 48px; width: max-content; margin: 0 auto; }

  .mlm-chart ul { display: flex; justify-content: center; list-style: none; margin: 0; padding: 26px 0 0; position: relative; }
  .mlm-chart li { display: flex; flex-direction: column; align-items: center; position: relative; padding: 26px 9px 0; }

  /* connector lines */
  .mlm-chart li::before, .mlm-chart li::after {
    content: ''; position: absolute; top: 0; right: 50%; width: 50%; height: 26px;
    border-top: 1.5px solid var(--line);
  }
  .mlm-chart li::after { right: auto; left: 50%; border-left: 1.5px solid var(--line); }
  .mlm-chart li::before { border-right: 1.5px solid var(--line); }
  .mlm-chart li:only-child { padding-top: 0; }
  .mlm-chart li:only-child::before, .mlm-chart li:only-child::after { display: none; }
  .mlm-chart li:first-child::before, .mlm-chart li:last-child::after { border-top: none; }
  .mlm-chart li:last-child::before { border-right: 1.5px solid var(--line); border-radius: 0 10px 0 0; }
  .mlm-chart li:first-child::after { border-radius: 10px 0 0 0; }
  .mlm-chart ul ul::before {
    content: ''; position: absolute; top: 0; left: 50%; width: 0; height: 26px;
    border-left: 1.5px solid var(--line);
  }
  /* root has no incoming connectors */
  .mlm-chart > ul { padding-top: 0; }
  .mlm-chart > ul > li { padding-top: 0; }
  .mlm-chart > ul > li::before, .mlm-chart > ul > li::after { display: none; }

  .mlm-chart li.collapsed > ul { display: none; }
  .mlm-chart li.collapsed > .mlm-toggle { color: var(--gold); border-color: var(--line); }

  .mlm-node {
    position: relative; z-index: 1; width: 158px; padding: 13px 14px 11px; border-radius: 16px; cursor: pointer;
    background: linear-gradient(180deg, rgba(22,12,58,.95), rgba(7,4,26,.95));
    border: 1px solid color-mix(in srgb, var(--accent) 45%, transparent);
    box-shadow: 0 12px 28px -14px rgba(0,0,0,.85);
    transition: transform .18s, box-shadow .18s; text-align: center;
  }
  .mlm-node:hover {
    transform: translateY(-3px);
    box-shadow: 0 0 0 1px var(--accent), 0 16px 36px -12px rgba(0,0,0,.9);
  }
  .mlm-node.root {
    background: linear-gradient(135deg, #f4cf6a, #c89a3c); border-color: #f4cf6a; width: 172px;
    box-shadow: 0 0 0 1px rgba(244,207,106,.7), 0 18px 44px -12px rgba(244,207,106,.45);
  }
  .mlm-ava {
    width: 40px; height: 40px; border-radius: 50%; margin: 0 auto 8px; display: grid; place-items: center;
    font-family: var(--display); font-weight: 600; font-size: 17px; color: #1a0d3d;
    background: linear-gradient(135deg, var(--accent), color-mix(in srgb, var(--accent) 55%, #1a0d3d));
    border: 2px solid color-mix(in srgb, var(--accent) 80%, white);
  }
  .mlm-node.root .mlm-ava { background: #1a0d3d; color: var(--gold); border-color: rgba(26,13,61,.6); }
  .mlm-nm { font-size: 12.5px; font-weight: 600; color: var(--ink); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .mlm-node.root .mlm-nm { color: #1a0d3d; }
  .mlm-cm { font-family: var(--display); font-size: 14px; font-weight: 600; color: var(--moon); margin-top: 3px; }
  .mlm-node.root .mlm-cm { color: #2a1259; }
  .mlm-chips { display: flex; gap: 4px; justify-content: center; margin-top: 7px; flex-wrap: wrap; }
  .mlm-chips span {
    font-size: 9.5px; padding: 2px 8px; border-radius: 999px; color: var(--ink-dim);
    background: rgba(246,239,224,.07); border: 1px solid var(--line-soft);
  }
  .mlm-node.root .mlm-chips span { color: #2a1259; background: rgba(26,13,61,.12); border-color: rgba(26,13,61,.25); }

  .mlm-toggle {
    position: relative; z-index: 1; margin-top: 8px; padding: 3px 12px; border-radius: 999px; cursor: pointer;
    background: rgba(7,4,26,.85); border: 1px solid var(--line-soft); color: var(--ink-dim);
    font-family: var(--thai); font-size: 10px; transition: all .2s;
  }
  .mlm-toggle:hover { color: var(--gold); border-color: var(--gold); }

  /* node detail floating card */
  .mlm-detail {
    position: absolute; top: 14px; right: 14px; z-index: 5; width: 250px; padding: 18px;
    border-radius: 16px; border: 1px solid var(--line);
    background: linear-gradient(180deg, rgba(22,12,58,.97), rgba(7,4,26,.97));
    box-shadow: 0 24px 60px -18px rgba(0,0,0,.9);
  }
  .mlm-detail-x {
    position: absolute; top: 10px; right: 12px; background: none; border: none; color: var(--ink-faint);
    cursor: pointer; font-size: 13px;
  }
  .mlm-detail-x:hover { color: var(--gold); }
  .mlm-detail-ava {
    width: 42px; height: 42px; border-radius: 50%; display: grid; place-items: center; flex: none;
    font-family: var(--display); font-weight: 600; font-size: 17px; color: #1a0d3d;
    background: linear-gradient(135deg, var(--accent, var(--gold)), color-mix(in srgb, var(--accent, var(--gold)) 55%, #1a0d3d));
  }
  .mlm-detail-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
  .mlm-detail-grid > div {
    background: rgba(7,4,26,.7); border: 1px solid var(--line-soft); border-radius: 10px;
    padding: 8px 4px; text-align: center;
  }
  .mlm-detail-grid b { display: block; font-family: var(--display); font-size: 14px; color: var(--moon); }
  .mlm-detail-grid span { font-size: 9.5px; color: var(--ink-faint); }

  /* list view rows */
  .mlm-row {
    display: flex; align-items: center; gap: 12px; position: relative;
    padding: 10px 14px; border-radius: 12px; margin-bottom: 6px;
    background: rgba(7,4,26,.5); border: 1px solid var(--line-soft);
  }
  .mlm-row-guide {
    position: absolute; left: 0; top: 0; bottom: 0; width: 2px; border-radius: 2px;
    background: linear-gradient(180deg, transparent, var(--line), transparent);
  }
  .mlm-row-ava {
    width: 34px; height: 34px; border-radius: 50%; display: grid; place-items: center; flex: none;
    font-family: var(--display); font-weight: 600; font-size: 14px; color: #1a0d3d;
    background: linear-gradient(135deg, var(--accent, var(--gold)), color-mix(in srgb, var(--accent, var(--gold)) 55%, #1a0d3d));
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
    fetchedAt: init.fetchedAt,
    refUrl: init.refUrl || '',
    refreshing: false,
    copied: false,

    // network chart state
    view: 'chart',
    zoom: 1,
    hasTree: false,
    flat: [],       // flattened nodes for the list view
    selected: null, // node detail card
    searchQ: '',

    LEVEL_COLORS: ['#f4cf6a', '#f4cf6a', '#9ec6f5', '#b07cff', '#ff8fd4', '#7ee0c3'],

    init() {
      this.$nextTick(() => {
        this.renderChart();
        this.buildTree();
        this.renderQr();
      });
    },

    /* ── formatting helpers ── */
    formatFetched() {
      if (!this.fetchedAt) return '';
      const d = new Date(this.fetchedAt);
      return d.toLocaleString('th-TH', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) + ' น.';
    },
    formatDate(iso) {
      if (!iso) return '—';
      const d = new Date(iso);
      return d.toLocaleDateString('th-TH', { day: '2-digit', month: 'short', year: '2-digit' });
    },
    statusLabel(s) { return { paid: 'จ่ายแล้ว', approved: 'อนุมัติ', pending: 'รอ', rejected: 'ปฏิเสธ' }[s] || s; },
    badgeStyle(s) {
      // Alpine ผูก :style ด้วยสตริงจะเขียนทับ attribute style ทั้งก้อน
      // สไตล์พื้นฐานของ badge จึงต้องรวมมาในนี้ ไม่ใช่เขียนแยกไว้ที่ style=""
      // (ไม่งั้น padding/มุมมน/ฟอนต์หายหมดตั้งแต่ Alpine บูต)
      const base = 'display:inline-block;padding:3px 12px;border-radius:99px;'
        + 'font-family:var(--display);font-size:10px;letter-spacing:.1em;text-transform:uppercase;';
      const map = {
        paid:     'background:rgba(120,200,120,.18);color:#9eddae;border:1px solid rgba(120,200,120,.35)',
        approved: 'background:rgba(120,180,255,.18);color:#9ec6f5;border:1px solid rgba(120,180,255,.35)',
        pending:  'background:linear-gradient(135deg,rgba(244,207,106,.2),rgba(244,207,106,.04));color:var(--gold);border:1px solid var(--line)',
        rejected: 'background:rgba(255,143,212,.18);color:#f59999;border:1px solid rgba(255,143,212,.35)',
      };
      return base + (map[s] || map.pending);
    },

    /* ── referral ── */
    async copyRef() {
      const t = this.refUrl;
      try {
        await navigator.clipboard.writeText(t);
      } catch (_) {
        const ta = document.createElement('textarea');
        ta.value = t; document.body.appendChild(ta); ta.select();
        document.execCommand('copy'); ta.remove();
      }
      this.copied = true;
      setTimeout(() => this.copied = false, 2200);
    },
    renderQr() {
      const el = document.getElementById('refQr');
      if (!el || !window.QRCode || !this.refUrl) return;
      new QRCode(el, {
        text: this.refUrl, width: 112, height: 112,
        colorDark: '#1a0d3d', colorLight: '#fdf8ec',
        correctLevel: QRCode.CorrectLevel.M,
      });
    },

    /* ── earnings chart ── */
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
            backgroundColor: (c) => {
              const g = c.chart.ctx.createLinearGradient(0, 0, 0, c.chart.height || 280);
              g.addColorStop(0, 'rgba(244,207,106,.28)');
              g.addColorStop(1, 'rgba(244,207,106,.01)');
              return g;
            },
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

    /* ── network chart (ผังสายงาน) ── */
    nodeMap: null,

    buildTree() {
      const root = this.treeData?.tree;
      const host = document.getElementById('mlmChart');
      if (!root || typeof root !== 'object' || !host) { this.hasTree = false; return; }

      this.nodeMap = new Map();
      this.flat = [];
      let seq = 0;

      const teamOf = (n) => (Array.isArray(n.children) ? n.children : [])
        .reduce((sum, c) => sum + 1 + teamOf(c), 0);

      const build = (n, level) => {
        const li = document.createElement('li');
        const kids = Array.isArray(n.children) ? n.children : [];
        const key = 'n' + (seq++);
        const color = this.LEVEL_COLORS[Math.min(level, this.LEVEL_COLORS.length - 1)];
        const name = (n.name ?? n.label ?? 'ไม่ระบุชื่อ').toString();
        const commissionRaw = Number(n.fortune_commission ?? n.earnings ?? 0) || 0;
        // Prefer upstream's true counts — the local walk undercounts when the
        // tree is depth-clipped at 5 levels.
        const team = Number(n.total_team_members ?? n.team_size ?? 0) || teamOf(n);
        const meta = {
          key, level, color,
          name,
          initial: [...name][0] ?? '?', // spread = no surrogate-pair split on emoji
          direct: Number(n.direct_referrals ?? 0) || kids.length,
          team,
          commissionRaw,
          commission: commissionRaw.toLocaleString('th-TH'),
          levelLabel: level === 0 ? 'คุณ (ต้นสาย)' : (level === 1 ? 'สายตรง · ชั้น 1' : `ชั้น ${level}`),
        };
        this.nodeMap.set(key, meta);
        if (level > 0) this.flat.push(meta);

        const card = document.createElement('div');
        card.className = 'mlm-node' + (level === 0 ? ' root' : '');
        card.style.setProperty('--accent', color);
        card.dataset.node = key;

        const ava = document.createElement('div');
        ava.className = 'mlm-ava'; ava.textContent = meta.initial;
        const nm = document.createElement('div');
        nm.className = 'mlm-nm'; nm.textContent = name; nm.title = name;
        card.append(ava, nm);
        if (commissionRaw > 0 || level === 0) {
          const cm = document.createElement('div');
          cm.className = 'mlm-cm'; cm.textContent = `฿${meta.commission}`;
          card.append(cm);
        }
        const chips = document.createElement('div');
        chips.className = 'mlm-chips';
        const c1 = document.createElement('span'); c1.textContent = `ตรง ${meta.direct}`;
        const c2 = document.createElement('span'); c2.textContent = `ทีม ${team}`;
        chips.append(c1, c2);
        card.append(chips);
        li.append(card);

        if (kids.length) {
          const tg = document.createElement('button');
          tg.className = 'mlm-toggle'; tg.dataset.toggle = '1';
          tg.textContent = `▾ สาย ${kids.length}`;
          li.append(tg);
          const ul = document.createElement('ul');
          kids.forEach(k => ul.append(build(k, level + 1)));
          li.append(ul);
        }
        return li;
      };

      const topUl = document.createElement('ul');
      topUl.append(build(root, 0));
      host.replaceChildren(topUl);
      this.hasTree = true;

      // Big trees start folded at depth ≥ 2 so first paint stays readable.
      if (this.nodeMap.size > 60) this.collapseAll();

      this.$nextTick(() => this.zoomFit());
      this.wireChartEvents();
    },

    wireChartEvents() {
      const host = document.getElementById('mlmChart');
      const vp = document.getElementById('mlmViewport');
      if (!host || !vp || host.dataset.wired) return;
      host.dataset.wired = '1';

      host.addEventListener('click', (e) => {
        const tg = e.target.closest('[data-toggle]');
        if (tg) {
          const li = tg.closest('li');
          li.classList.toggle('collapsed');
          tg.textContent = (li.classList.contains('collapsed') ? '▸' : '▾') + tg.textContent.slice(1);
          return;
        }
        const card = e.target.closest('[data-node]');
        if (card) this.selected = this.nodeMap.get(card.dataset.node) || null;
      });

      // drag-to-pan (mouse; touch scrolls natively)
      let drag = null;
      vp.addEventListener('mousedown', (e) => {
        if (e.target.closest('.mlm-node, .mlm-toggle')) return;
        drag = { x: e.clientX, y: e.clientY, l: vp.scrollLeft, t: vp.scrollTop };
        vp.classList.add('dragging');
      });
      window.addEventListener('mousemove', (e) => {
        if (!drag) return;
        vp.scrollLeft = drag.l - (e.clientX - drag.x);
        vp.scrollTop  = drag.t - (e.clientY - drag.y);
      });
      window.addEventListener('mouseup', () => { drag = null; vp.classList.remove('dragging'); });
    },

    setZoom(z) {
      this.zoom = Math.min(1.6, Math.max(0.35, z));
      const c = document.getElementById('mlmCanvas');
      if (c) c.style.transform = `scale(${this.zoom})`;
    },
    zoomBy(dz) { this.setZoom(this.zoom + dz); },
    zoomFit() {
      const vp = document.getElementById('mlmViewport');
      const c = document.getElementById('mlmCanvas');
      if (!vp || !c) return;
      c.style.transform = 'scale(1)';
      const w = c.scrollWidth || 1;
      this.setZoom(Math.min(1, (vp.clientWidth - 24) / w));
    },
    expandAll() {
      document.querySelectorAll('#mlmChart li.collapsed').forEach(li => {
        li.classList.remove('collapsed');
        const tg = li.querySelector(':scope > .mlm-toggle');
        if (tg) tg.textContent = '▾' + tg.textContent.slice(1);
      });
      this.$nextTick(() => this.zoomFit());
    },
    collapseAll() {
      // fold everything below level 1 (root's direct children stay visible)
      document.querySelectorAll('#mlmChart > ul > li > ul li').forEach(li => {
        if (li.querySelector(':scope > ul')) {
          li.classList.add('collapsed');
          const tg = li.querySelector(':scope > .mlm-toggle');
          if (tg) tg.textContent = '▸' + tg.textContent.slice(1);
        }
      });
      this.$nextTick(() => this.zoomFit());
    },

    filteredFlat() {
      const q = this.searchQ.trim().toLowerCase();
      if (!q) return this.flat;
      return this.flat.filter(n => n.name.toLowerCase().includes(q));
    },

    /* ── commissions + admin ── */
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
