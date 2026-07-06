</section>

  <!-- Financial Overview Section -->
  <?php if(!empty($chartData) || !empty($typeBreakdown)): ?>
  <section class="trend-section">
    <div class="section-header">
      <h2 class="section-title">
        <span class="section-icon"><i class="fas fa-chart-line"></i></span>
        Financial Overview
      </h2>
      <div class="section-divider"></div>
    </div>
    <div class="financial-charts">
      <div class="chart-widget">
        <div class="chart-header">
          <h3 class="chart-title">Revenue vs Maintenance Costs</h3>
          <div class="quick-filters">
            <button type="button" class="btn btn-sm btn-outline active" onclick="switchChartType('profitChart', 'bar', this)">Bar</button>
            <button type="button" class="btn btn-sm btn-outline" onclick="switchChartType('profitChart', 'line', this)">Line</button>
          </div>
        </div>
        <div class="chart-container">
          <canvas id="profitChart"></canvas>
        </div>
      </div>
      <div class="chart-widget">
        <div class="chart-header">
          <h3 class="chart-title">Revenue by Vehicle Type</h3>
          <div class="quick-filters">
            <button type="button" class="btn btn-sm btn-outline active" onclick="switchChartType('typeChart', 'doughnut', this)">Doughnut</button>
            <button type="button" class="btn btn-sm btn-outline" onclick="switchChartType('typeChart', 'pie', this)">Pie</button>
          </div>
        </div>
        <div class="chart-container">
          <canvas id="typeChart"></canvas>
        </div>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <!-- Vehicle Utilization Report -->
  <section class="data-section">
    <div class="table-header">
      <div class="section-header" style="margin:0">
        <h2 class="section-title">
          <span class="section-icon"><i class="fas fa-tachometer-alt"></i></span>
          Vehicle Utilization Report (Last 30 Days)
        </h2>
      </div>
    </div>
    <div class="table-container">
      <table class="data-table">
        <thead>
          <tr>
            <th>Vehicle</th>
            <th>Rented Days (30d)</th>
            <th>Utilization %</th>
            <th>Status</th>
            <th>Forecast Next Month</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($utilizationTable)): ?>
            <?php foreach ($utilizationTable as $row): ?>
              <?php $status = $row['status_label']; $badgeClass = 'status-' . strtolower($status); ?>
              <tr>
                <td>
                  <strong><?= h($row['identifier']) ?></strong>
                  <?php if (!empty($row['current_status'])): ?>
                    <div style="font-size:0.8rem;color:var(--text-muted);margin-top:2px">Current: <?= h(ucfirst($row['current_status'])) ?></div>
                  <?php endif; ?>
                </td>
                <td><?= number_format((int)$row['total_rented_days_last30']) ?></td>
                <td><?= (int)$row['utilization_rate'] ?>%</td>
                <td><span class="status-badge <?= $badgeClass ?>"><?= h($status) ?></span></td>
                <td><?= (int)$row['forecast_utilization_next'] ?>%</td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="5" style="text-align:center;padding:40px;color:var(--text-muted)">
                <i class="fas fa-inbox" style="font-size:2rem;display:block;margin-bottom:12px;opacity:0.4"></i>
                No utilization data available for the current period.
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <!-- Detailed Reports Tables -->
  <?php if($type==='All' || $type==='Rentals'): ?>
  <section class="data-section">
    <div class="table-header">
      <div class="section-header" style="margin:0">
        <h2 class="section-title">
          <span class="section-icon"><i class="fas fa-car"></i></span>
          Rentals Summary
        </h2>
      </div>
    </div>
    <div class="table-container">
      <table class="data-table">
        <thead>
          <tr>
            <th>ID</th><th>Vehicle</th><th>Plate</th><th>Start Date</th><th>End Date</th>
            <th>Total Cost</th><th>Downpayment</th><th>Balance</th><th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php while($r=$rentals->fetch_assoc()): ?>
          <tr>
            <td><strong>#<?=$r['id']?></strong></td>
            <td><?=h($r['make_model'])?></td>
            <td><code style="background:rgba(255,255,255,0.05);padding:2px 6px;border-radius:4px;font-size:0.8rem"><?=h($r['plate_no'])?></code></td>
            <td><?=date('M j, Y', strtotime($r['start_date']))?></td>
            <td><?=date('M j, Y', strtotime($r['end_date']))?></td>
            <td><strong>₱<?=number_format($r['total_cost'],2)?></strong></td>
            <td>₱<?=number_format($r['downpayment']??0,2)?></td>
            <td>₱<?=number_format($r['balance_due']??0,2)?></td>
            <td><span class="status-badge status-<?=strtolower($r['status'])?>"><?=ucfirst($r['status'])?></span></td>
          </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </section>
  <?php endif; ?>

  <?php if($type==='All' || $type==='Returns'): ?>
  <section class="data-section">
    <div class="table-header">
      <div class="section-header" style="margin:0">
        <h2 class="section-title">
          <span class="section-icon"><i class="fas fa-clipboard-check"></i></span>
          Return Inspections
        </h2>
      </div>
    </div>
    <div class="table-container">
      <table class="data-table">
        <thead>
          <tr>
            <th>Customer</th><th>Vehicle</th><th>Rental Period</th>
            <th>Return Date</th><th>Fuel Level</th><th>Condition</th><th>Penalty</th><th>Final Cost</th>
          </tr>
        </thead>
        <tbody>
          <?php while($ri=$returnInspections->fetch_assoc()): ?>
          <tr>
            <td><strong><?=h($ri['customer_name'])?></strong></td>
            <td><?=h($ri['make_model'])?> <code style="font-size:0.75rem">(<?=h($ri['plate_no'])?>)</code></td>
            <td><?=date('M j', strtotime($ri['start_date']))?> - <?=date('M j', strtotime($ri['end_date']))?></td>
            <td><?=date('M j, Y g:i A', strtotime($ri['actual_return_date'] . ' ' . $ri['actual_return_time']))?></td>
            <td><span class="status-badge <?=$ri['fuel_level'] === 'Full' ? 'status-completed' : 'status-ongoing'?>"><?=ucfirst($ri['fuel_level'])?></span></td>
            <td><?=h($ri['return_condition'])?></td>
            <td>₱<?=number_format($ri['penalty_amount'],2)?></td>
            <td><strong>₱<?=number_format($ri['final_cost'],2)?></strong></td>
          </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </section>
  <?php endif; ?>

  <?php if($type==='All' || $type==='Maintenance'): ?>
  <section class="data-section">
    <div class="table-header">
      <div class="section-header" style="margin:0">
        <h2 class="section-title">
          <span class="section-icon"><i class="fas fa-tools"></i></span>
          Maintenance Summary
        </h2>
      </div>
    </div>
    <div class="table-container">
      <table class="data-table">
        <thead>
          <tr>
            <th>ID</th><th>Vehicle</th><th>Plate</th><th>Type</th>
            <th>Schedule Date</th><th>Estimated Cost</th><th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php while($m=$maintenance->fetch_assoc()): ?>
          <tr>
            <td><strong>#<?=$m['id']?></strong></td>
            <td><?=h($m['make_model'])?></td>
            <td><code style="background:rgba(255,255,255,0.05);padding:2px 6px;border-radius:4px;font-size:0.8rem"><?=h($m['plate_no'])?></code></td>
            <td><?=h($m['maintenance_category'] ?? $m['type'] ?? 'N/A')?></td>
            <td><?=date('M j, Y', strtotime($m['schedule_date']))?></td>
            <td><strong>₱<?=number_format($m['total_cost'],2)?></strong></td>
            <td><span class="status-badge status-<?=strtolower($m['status'])?>"><?=ucfirst($m['status'])?></span></td>
          </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </section>
  <?php endif; ?>

  <!-- Export Section -->
  <section class="export-section card">
    <div class="section-header">
      <h2 class="section-title">
        <span class="section-icon"><i class="fas fa-download"></i></span>
        Export Data
      </h2>
    </div>
    <div class="export-grid">
      <button class="btn btn-secondary" onclick="exportData('rentals')">
        <i class="fas fa-file-csv"></i> Export Rentals CSV
      </button>
      <button class="btn btn-secondary" onclick="exportData('maintenance')">
        <i class="fas fa-file-csv"></i> Export Maintenance CSV
      </button>
      <button class="btn btn-secondary" onclick="exportData('returns')">
        <i class="fas fa-file-csv"></i> Export Returns CSV
      </button>
      <button class="btn btn-outline" onclick="window.print()">
        <i class="fas fa-print"></i> Print Full Report
      </button>
    </div>
  </section>
</div>

<!-- KPI Modal -->
<div id="kpiModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h2 class="modal-title">
        <i class="fas fa-th-large"></i> All Metrics Dashboard
      </h2>
      <button class="modal-close" onclick="closeKPIModal()">&times;</button>
    </div>
    <div class="modal-body">
      <div class="metrics-grid">
        <div class="metric-card">
          <div class="metric-header">
            <span class="metric-label">Total Revenue</span>
            <i class="fas fa-peso-sign" style="color:var(--brand)"></i>
          </div>
          <p class="metric-value" id="modalTotalRevenue">₱<?=number_format($totalRevenue,2)?></p>
          <div class="metric-change positive">Total earnings to date</div>
        </div>
        <div class="metric-card">
          <div class="metric-header">
            <span class="metric-label">Total Rentals</span>
            <i class="fas fa-car" style="color:var(--success)"></i>
          </div>
          <p class="metric-value" id="modalTotalRentals"><?=number_format($totalRentals)?></p>
          <div class="metric-change positive">Period transactions</div>
        </div>
        <div class="metric-card">
          <div class="metric-header">
            <span class="metric-label">Net Profit</span>
            <i class="fas fa-coins" style="color:var(--brand2)"></i>
          </div>
          <p class="metric-value" id="modalNetProfit">₱<?=number_format($netProfit,2)?></p>
          <div class="metric-change <?= $netProfit >= 0 ? 'positive' : 'negative' ?>">Revenue minus maintenance</div>
        </div>
        <div class="metric-card">
          <div class="metric-header">
            <span class="metric-label">Profit Margin</span>
            <i class="fas fa-percent" style="color:var(--accent)"></i>
          </div>
          <p class="metric-value" id="modalProfitMargin"><?=number_format($profitMargin,1)?>%</p>
          <div class="metric-change positive">Efficiency ratio</div>
        </div>
        <div class="metric-card">
          <div class="metric-header">
            <span class="metric-label">Downpayments</span>
            <i class="fas fa-hand-holding-dollar" style="color:var(--warning)"></i>
          </div>
          <p class="metric-value" id="modalTotalDownpayments">₱<?=number_format($totalDownpayments,2)?></p>
          <div class="metric-change positive">Collected upfront</div>
        </div>
        <div class="metric-card">
          <div class="metric-header">
            <span class="metric-label">Outstanding Balances</span>
            <i class="fas fa-clock" style="color:var(--error)"></i>
          </div>
          <p class="metric-value" id="modalTotalBalances">₱<?=number_format($totalBalances,2)?></p>
          <div class="metric-change negative">Pending collection</div>
        </div>
        <div class="metric-card">
          <div class="metric-header">
            <span class="metric-label">Maintenance Cost</span>
            <i class="fas fa-wrench" style="color:var(--warning)"></i>
          </div>
          <p class="metric-value" id="modalMaintenanceCost">₱<?=number_format($maintenanceCost,2)?></p>
          <div class="metric-change negative">Operational expense</div>
        </div>
        <div class="metric-card">
          <div class="metric-header">
            <span class="metric-label">Penalties & Fees</span>
            <i class="fas fa-exclamation-circle" style="color:var(--error)"></i>
          </div>
          <p class="metric-value" id="modalTotalPenalties">₱<?=number_format($totalPenalties + $totalExtraFees,2)?></p>
          <div class="metric-change negative">Late returns & damages</div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
// Data from PHP
const monthlyDemandData = <?= json_encode($monthlyDemandData) ?>;
const monthlyRevenueData = <?= json_encode($monthlyRevenueData) ?>;
const maintenanceTrendData = <?= json_encode($maintenanceTrendData) ?>;
const peakDaysData = <?= json_encode($peakDaysData) ?>;
const mostRentedData = <?= json_encode($mostRentedData) ?>;
const chartData = <?=json_encode($chartData)?>;
const typeBreakdown = <?=json_encode($typeBreakdown)?>;

// Chart Configuration
Chart.defaults.color = '#9ca3af';
Chart.defaults.borderColor = 'rgba(255,255,255,0.05)';
Chart.defaults.font.family = "'Inter', system-ui, sans-serif";

// 1. Monthly Rental Demand Chart
const rentalDemandCtx = document.getElementById('rentalDemandChart');
if (rentalDemandCtx && monthlyDemandData.length > 0) {
    new Chart(rentalDemandCtx, {
        type: 'line',
        data: {
            labels: monthlyDemandData.map(d => { const [y,m] = d.month.split('-'); return m+'/'+y.slice(2); }),
            datasets: [{
                label: 'Rentals',
                data: monthlyDemandData.map(d => d.count),
                borderColor: '#5dd0ff',
                backgroundColor: 'rgba(93, 208, 255, 0.1)',
                fill: true,
                tension: 0.4,
                pointBackgroundColor: '#5dd0ff',
                pointBorderColor: '#0b0d10',
                pointBorderWidth: 2,
                pointRadius: 4,
                pointHoverRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#6b7280', font: { size: 11 } } },
                x: { grid: { display: false }, ticks: { color: '#6b7280', font: { size: 10 } } }
            }
        }
    });
} else if (rentalDemandCtx) {
    rentalDemandCtx.parentElement.innerHTML = '<div class="empty-state"><i class="fas fa-chart-line"></i><div class="empty-state-title">No rental data available</div></div>';
}

// 2. Monthly Revenue Trend Chart
const revenueTrendCtx = document.getElementById('revenueTrendChart');
if (revenueTrendCtx && monthlyRevenueData.length > 0) {
    new Chart(revenueTrendCtx, {
        type: 'line',
        data: {
            labels: monthlyRevenueData.map(d => { const [y,m] = d.month.split('-'); return m+'/'+y.slice(2); }),
            datasets: [{
                label: 'Revenue (₱)',
                data: monthlyRevenueData.map(d => d.revenue),
                borderColor: '#7cffc7',
                backgroundColor: 'rgba(124, 255, 199, 0.1)',
                fill: true,
                tension: 0.4,
                pointBackgroundColor: '#7cffc7',
                pointBorderColor: '#0b0d10',
                pointBorderWidth: 2,
                pointRadius: 4,
                pointHoverRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false }, tooltip: { callbacks: { label: (ctx) => '₱' + ctx.parsed.y.toLocaleString() } } },
            scales: {
                y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#6b7280', font: { size: 11 }, callback: (v) => '₱' + (v/1000).toFixed(0) + 'k' } },
                x: { grid: { display: false }, ticks: { color: '#6b7280', font: { size: 10 } } }
            }
        }
    });
} else if (revenueTrendCtx) {
    revenueTrendCtx.parentElement.innerHTML = '<div class="empty-state"><i class="fas fa-chart-line"></i><div class="empty-state-title">No revenue data available</div></div>';
}

// 3. Maintenance Frequency Trend Chart
const maintenanceTrendCtx = document.getElementById('maintenanceTrendChart');
if (maintenanceTrendCtx && maintenanceTrendData.length > 0) {
    new Chart(maintenanceTrendCtx, {
        type: 'bar',
        data: {
            labels: maintenanceTrendData.map(d => { const [y,m] = d.month.split('-'); return m+'/'+y.slice(2); }),
            datasets: [{
                label: 'Maintenance Events',
                data: maintenanceTrendData.map(d => d.count),
                backgroundColor: 'rgba(245, 158, 11, 0.7)',
                borderColor: '#f59e0b',
                borderWidth: 1,
                borderRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#6b7280', font: { size: 11 } } },
                x: { grid: { display: false }, ticks: { color: '#6b7280', font: { size: 10 } } }
            }
        }
    });
} else if (maintenanceTrendCtx) {
    maintenanceTrendCtx.parentElement.innerHTML = '<div class="empty-state"><i class="fas fa-wrench"></i><div class="empty-state-title">No maintenance data available</div></div>';
}

// 4. Peak Rental Days Chart
const peakDaysCtx = document.getElementById('peakDaysChart');
if (peakDaysCtx && peakDaysData.length > 0) {
    const dayOrder = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    const sortedData = peakDaysData.sort((a, b) => dayOrder.indexOf(a.day_name) - dayOrder.indexOf(b.day_name));
    new Chart(peakDaysCtx, {
        type: 'bar',
        data: {
            labels: sortedData.map(d => d.day_name.slice(0, 3)),
            datasets: [{
                label: 'Rentals',
                data: sortedData.map(d => d.count),
                backgroundColor: ['rgba(99, 102, 241, 0.6)', 'rgba(99, 102, 241, 0.6)', 'rgba(99, 102, 241, 0.6)', 'rgba(99, 102, 241, 0.6)', 'rgba(99, 102, 241, 0.7)', 'rgba(236, 72, 153, 0.7)', 'rgba(236, 72, 153, 0.7)'],
                borderRadius: 4,
                borderSkipped: false
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#6b7280', font: { size: 11 } } },
                x: { grid: { display: false }, ticks: { color: '#6b7280', font: { size: 10 } } }
            }
        }
    });
} else if (peakDaysCtx) {
    peakDaysCtx.parentElement.innerHTML = '<div class="empty-state"><i class="fas fa-calendar"></i><div class="empty-state-title">No data available</div></div>';
}

// 5. Most Rented Vehicles Chart (Horizontal Bar)
const mostRentedCtx = document.getElementById('mostRentedChart');
if (mostRentedCtx && mostRentedData.length > 0) {
    new Chart(mostRentedCtx, {
        type: 'bar',
        data: {
            labels: mostRentedData.map(d => d.make_model.length > 30 ? d.make_model.slice(0, 30) + '...' : d.make_model),
            datasets: [{
                label: 'Rental Count',
                data: mostRentedData.map(d => d.rental_count),
                backgroundColor: 'rgba(93, 208, 255, 0.6)',
                borderColor: '#5dd0ff',
                borderWidth: 1,
                borderRadius: 4
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#6b7280', font: { size: 11 } } },
                y: { grid: { display: false }, ticks: { color: '#9ca3af', font: { size: 10 } } }
            }
        }
    });
} else if (mostRentedCtx) {
    mostRentedCtx.parentElement.innerHTML = '<div class="empty-state"><i class="fas fa-car"></i><div class="empty-state-title">No vehicle rental data available</div></div>';
}

// Financial Charts
let profitChart, typeChart;
function initializeFinancialCharts() {
    if(chartData.length){
        const profitCtx = document.getElementById('profitChart');
        if(profitCtx){
            profitChart = new Chart(profitCtx, {
                type: 'bar',
                data: {
                    labels: chartData.map(x => x.month),
                    datasets: [
                        {
                            label: 'Revenue (₱)',
                            data: chartData.map(x => x.revenue),
                            backgroundColor: 'rgba(93, 208, 255, 0.7)',
                            borderColor: '#5dd0ff',
                            borderWidth: 1,
                            borderRadius: 4
                        },
                        {
                            label: 'Maintenance (₱)',
                            data: chartData.map(x => x.maintenance),
                            backgroundColor: 'rgba(239, 68, 68, 0.6)',
                            borderColor: '#ef4444',
                            borderWidth: 1,
                            borderRadius: 4
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { labels: { color: '#9ca3af', font: { size: 12 } } },
                        tooltip: { callbacks: { label: (ctx) => ctx.dataset.label + ': ₱' + ctx.parsed.y.toLocaleString() } }
                    },
                    scales: {
                        x: { ticks: { color: '#6b7280', font: { size: 11 } }, grid: { color: 'rgba(255,255,255,0.05)' } },
                        y: { ticks: { color: '#6b7280', font: { size: 11 }, callback: (v) => '₱' + (v/1000).toFixed(0) + 'k' }, grid: { color: 'rgba(255,255,255,0.05)' } }
                    }
                }
            });
        }
    }
    if(typeBreakdown.length){
        const typeCtx = document.getElementById('typeChart');
        if(typeCtx){
            typeChart = new Chart(typeCtx, {
                type: 'doughnut',
                data: {
                    labels: typeBreakdown.map(x => x.vehicle_type || 'Unspecified'),
                    datasets: [{
                        data: typeBreakdown.map(x => x.total),
                        backgroundColor: ['#5dd0ff', '#7cffc7', '#f59e0b', '#ef4444', '#8b5cf6', '#6366f1'],
                        borderWidth: 0,
                        hoverOffset: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom', labels: { color: '#9ca3af', font: { size: 11 }, padding: 15, usePointStyle: true } },
                        tooltip: { callbacks: { label: (ctx) => { const total = ctx.dataset.data.reduce((a,b) => a+b, 0); const pct = ((ctx.parsed / total) * 100).toFixed(1); return ctx.label + ': ₱' + ctx.parsed.toLocaleString() + ' (' + pct + '%)'; } }
                    }
                }
            });
        }
    }
}
initializeFinancialCharts();

// Chart Type Switching
function switchChartType(chartId, newType, btn) {
    const buttons = btn.parentElement.querySelectorAll('button');
    buttons.forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    if(chartId === 'profitChart' && profitChart) {
        profitChart.config.type = newType;
        if(newType === 'line') {
            profitChart.data.datasets.forEach(ds => {
                ds.backgroundColor = ds.label.includes('Revenue') ? 'rgba(93, 208, 255, 0.1)' : 'rgba(239, 68, 68, 0.1)';
                ds.fill = true;
                ds.tension = 0.4;
            });
        } else {
            profitChart.data.datasets[0].backgroundColor = 'rgba(93, 208, 255, 0.7)';
            profitChart.data.datasets[1].backgroundColor = 'rgba(239, 68, 68, 0.6)';
            profitChart.data.datasets.forEach(ds => { ds.fill = false; ds.tension = 0; });
        }
        profitChart.update();
    } else if(chartId === 'typeChart' && typeChart) {
        typeChart.config.type = newType;
        typeChart.update();
    }
}

// Quick Filters
function setQuickFilter(period) {
    const today = new Date();
    let from, to;
    switch(period) {
        case 'today': from = to = today.toISOString().split('T')[0]; break;
        case 'week': const ws = new Date(today); ws.setDate(today.getDate() - today.getDay()); from = ws.toISOString().split('T')[0]; to = today.toISOString().split('T')[0]; break;
        case 'month': from = new Date(today.getFullYear(), today.getMonth(), 1).toISOString().split('T')[0]; to = new Date(today.getFullYear(), today.getMonth() + 1, 0).toISOString().split('T')[0]; break;
        case 'year': from = new Date(today.getFullYear(), 0, 1).toISOString().split('T')[0]; to = new Date(today.getFullYear(), 11, 31).toISOString().split('T')[0]; break;
    }
    document.getElementById('from').value = from;
    document.getElementById('to').value = to;
    setTimeout(() => window.location.href = `reports.php?from=${from}&to=${to}&type=${document.getElementById('type').value}`, 100);
}

// Refresh Stats
async function refreshStats() {
    const from = document.getElementById('from').value;
    const to = document.getElementById('to').value;
    try {
        const response = await fetch(`reports.php?ajax=quick_stats&from=${from}&to=${to}`);
        const stats = await response.json();
        document.getElementById('totalRevenue').textContent = '₱' + parseFloat(stats.total_revenue).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
        document.getElementById('totalRentals').textContent = parseInt(stats.total_rentals).toLocaleString();
        document.getElementById('totalMaintenanceCount').textContent = parseInt(stats.total_maintenance_count).toLocaleString();
        showToast('Stats refreshed successfully', true);
    } catch(error) { showToast('Failed to refresh stats', false); }
}

// Export Data
function exportData(type) {
    const from = document.getElementById('from').value;
    const to = document.getElementById('to').value;
    window.open(`reports.php?ajax=export_data&type=${type}&from=${from}&to=${to}`, '_blank');
    showToast(`Exporting ${type} data...`, true);
}

// Modal Functions
function openKPIModal() {
    document.getElementById('kpiModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}
function closeKPIModal() {
    document.getElementById('kpiModal').classList.remove('active');
    document.body.style.overflow = 'auto';
}
document.getElementById('kpiModal').addEventListener('click', (e) => { if(e.target === document.getElementById('kpiModal')) closeKPIModal(); });
document.addEventListener('keydown', (e) => { if(e.key === 'Escape') closeKPIModal(); });

// Toast Notifications
function showToast(message, isSuccess) {
    const existing = document.querySelector('.toast');
    if(existing) existing.remove();
    const toast = document.createElement('div');
    toast.className = `toast ${isSuccess ? 'toast-success' : 'toast-error'}`;
    toast.innerHTML = `<i class="fas ${isSuccess ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i> ${message}`;
    document.body.appendChild(toast);
    setTimeout(() => { toast.style.opacity = '0'; toast.style.transform = 'translateX(100%)'; setTimeout(() => toast.remove(), 300); }, 3000);
}
</script>

</body>
</html>
<?php $conn->close(); ?>
