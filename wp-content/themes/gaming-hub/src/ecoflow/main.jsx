import { createRoot } from 'react-dom/client';
import EnergyFlowDiagram from './EnergyFlowDiagram';

const mountNode = document.getElementById( 'ecoflow-energy-flow-root' );

if ( mountNode ) {
	const initial = mountNode.dataset.initial ? JSON.parse( mountNode.dataset.initial ) : {};
	const labels = window.gamingHubEcoflowFlow?.labels || {
		solar: 'ソーラー',
		grid: 'グリッド',
		home: 'ホーム',
		battery: 'バッテリー',
		pro: 'Delta Pro 3',
		delta: 'Delta 3 1500',
		acLink: 'AC 100V',
		flow: '電力フロー',
		inputTotal: '入力合計',
		outputTotal: '出力合計',
	};

	createRoot( mountNode ).render(
		<EnergyFlowDiagram initial={ initial } labels={ labels } />
	);
}
