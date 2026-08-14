import { createRoot } from 'react-dom/client';
import PowerwallFlowDiagram from './PowerwallFlowDiagram';

const mountNode = document.getElementById( 'powerwall-energy-flow-root' );

if ( mountNode ) {
	const initial = mountNode.dataset.initial ? JSON.parse( mountNode.dataset.initial ) : {};
	const labels = window.gamingHubPowerwallFlow?.labels || {
		solar: 'ソーラー (1.5kW)',
		powerwall: 'Powerwall 3',
		home: 'ホーム',
		model3: 'Model 3',
		grid: 'グリッド',
		gridNote: '買電のみ',
		flow: '電力フロー',
		import: '買電',
		simulated: 'デモデータ（時刻に応じて変化）',
	};

	createRoot( mountNode ).render(
		<PowerwallFlowDiagram initial={ initial } labels={ labels } />
	);
}
