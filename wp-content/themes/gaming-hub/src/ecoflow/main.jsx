import { createRoot } from 'react-dom/client';
import EnergyFlowDiagram from './EnergyFlowDiagram';

const mountNode = document.getElementById( 'ecoflow-energy-flow-root' );

if ( mountNode ) {
	const initial = mountNode.dataset.initial ? JSON.parse( mountNode.dataset.initial ) : {};
	const labels = window.gamingHubEcoflowFlow?.labels || {
		solar: 'Low Volt',
		hv: 'ハイボルト',
		grid: 'グリッド',
		gridCharge: 'グリッド補充電',
		deltaGrid: 'グリッド AC 入力',
		acInMeasured: '実測 · MQTT',
		home: '慎一の部屋',
		ups: '常時稼働エリア (UPS)',
		extra: 'Extra Battery 1kW',
		battery: 'バッテリー',
		pro: 'Delta Pro 3',
		delta: 'Delta 3 1500',
		dcLink: 'DC 12V',
		acLink: 'DC 12V',
		acOut: 'AC 出力',
		acOutMeasured: '実測 · MQTT',
		upsPlug: 'SwitchBot Plug',
		lvMeasured: '実測 · MQTT',
		flow: '電力フロー',
		inputTotal: '入力合計',
		outputTotal: '出力合計',
		todaySave: '今日 節約',
		todayBuy: '今日 買電',
		todayGen: '今日 発電',
	};

	createRoot( mountNode ).render(
		<EnergyFlowDiagram initial={ initial } labels={ labels } />
	);
}
